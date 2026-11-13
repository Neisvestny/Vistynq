<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Config\EnvLoader;
use Vistynq\Controllers\CanvasController;
use Vistynq\Controllers\NoteController;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Repositories\CanvasRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Services\JwtService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Container;
use Vistynq\Support\ContainerFactory;
use Vistynq\Support\Database;

/**
 * Общая обвязка интеграционных тестов R11/R12: та же тестовая БД, что у
 * существующих интеграционных тестов (EnvLoader → .env → vistynq), очистка
 * созданных сущностей в tearDown.
 *
 * Тесты вызывают контроллеры напрямую (минуя HTTP-пайплайн), поэтому
 * контракт проверяется на статусах/телах Response и ApiException.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Config $config;
    protected Database $db;
    protected Container $container;
    protected CanvasController $canvasController;
    protected NoteController $noteController;
    protected JwtService $jwt;
    protected UserRepository $users;
    protected CanvasRepository $canvases;

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdCanvasIds = [];

    protected function setUp(): void
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_pgsql extension is not available');
        }

        EnvLoader::load(__DIR__ . '/../../.env');

        $this->config = new Config();
        $this->container = new Container();
        // Те же биндинги, что в Kernel::boot() (R17): единая конфигурация
        // контейнера в ContainerFactory.
        ContainerFactory::configure($this->container, $this->config);
        $this->db = $this->container->get(Database::class);

        $this->jwt = new JwtService($this->config);
        $this->users = $this->container->get(UserRepository::class);
        $this->canvases = $this->container->get(CanvasRepository::class);
        $this->canvasController = $this->container->get(CanvasController::class);
        $this->noteController = $this->container->get(NoteController::class);
    }

    protected function tearDown(): void
    {
        // setUp мог завершиться skip'ом (нет pdo_pgsql) — тогда чистить нечего
        if (!isset($this->db)) {
            return;
        }

        $pdo = $this->db->pdo();
        // notes.author_id и direct_links.created_by без ON DELETE, поэтому
        // сначала канвасы (каскад уносит notes/links/tags/access), потом юзеры.
        foreach ($this->createdCanvasIds as $canvasId) {
            $this->canvases->delete($canvasId);
        }
        foreach ($this->createdUserIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }

        $this->createdCanvasIds = [];
        $this->createdUserIds = [];
    }

    /**
     * Пользователь с фиксированным префиксом почты (для массовой очистки).
     */
    protected function createUser(bool $verified = true): string
    {
        $email = sprintf('r11r12-%s@example.local', bin2hex(random_bytes(6)));
        $user = $this->users->insert($email, password_hash('test-pass123', PASSWORD_DEFAULT), 'R11/R12 Test');
        if ($verified) {
            $this->users->markEmailVerified($user->id);
        }
        $this->createdUserIds[] = $user->id;

        return $user->id;
    }

    protected function createCanvas(string $ownerId, string $title = 'Тестовый канвас'): string
    {
        $row = $this->canvases->create($ownerId, $title);
        $id = (string) $row['id'];
        $this->createdCanvasIds[] = $id;

        return $id;
    }

    protected function createNote(string $canvasId, string $authorId, string $title): string
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO notes (canvas_id, author_id, title) VALUES (:canvas_id, :author_id, :title) RETURNING id'
        );
        $stmt->execute(['canvas_id' => $canvasId, 'author_id' => $authorId, 'title' => $title]);

        return (string) $stmt->fetchColumn();
    }

    protected function createTag(string $canvasId, string $name): string
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tags (canvas_id, name) VALUES (:canvas_id, :name) RETURNING id'
        );
        $stmt->execute(['canvas_id' => $canvasId, 'name' => $name]);

        return (string) $stmt->fetchColumn();
    }

    protected function grantCanvasAccess(string $canvasId, string $userId, string $role): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO canvas_access (canvas_id, user_id, role) VALUES (:canvas_id, :user_id, :role)
             ON CONFLICT DO NOTHING'
        )->execute(['canvas_id' => $canvasId, 'user_id' => $userId, 'role' => $role]);
    }

    protected function grantNoteAccess(string $noteId, string $userId, string $role): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO note_access (note_id, user_id, role) VALUES (:note_id, :user_id, :role)
             ON CONFLICT DO NOTHING'
        )->execute(['note_id' => $noteId, 'user_id' => $userId, 'role' => $role]);
    }

    protected function setCanvasAccessLevel(string $canvasId, string $level): void
    {
        // CHECK canvases_public_slug_check (0004): public ↔ slug, private ↔ NULL.
        $this->db->pdo()->prepare(
            "UPDATE canvases
                SET access_level = :level,
                    public_slug = CASE WHEN :level = 'public'
                                       THEN COALESCE(public_slug, :slug)
                                       ELSE NULL END
              WHERE id = :id"
        )->execute([
            'level' => $level,
            'slug' => 'slug-' . str_replace('-', '', $canvasId),
            'id' => $canvasId,
        ]);
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    protected function request(
        string $method,
        string $path,
        array $routeParams = [],
        ?array $json = null,
        array $query = [],
        array $headers = [],
        ?string $authUserId = null,
        array $cookies = [],
    ): Request {
        $attributes = [];
        if ($json !== null) {
            $attributes['jsonBody'] = $json;
        }
        if ($authUserId !== null) {
            $attributes['authUserId'] = $authUserId;
        }

        return new Request($method, $path, $query, $headers, '', $cookies, $routeParams, $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true);

        $this->assertIsArray($decoded, 'тело ответа должно быть JSON: ' . $response->body());

        return $decoded;
    }

    /**
     * Ожидает ApiException с заданным HTTP-статусом; возвращает её для
     * проверки code/details.
     */
    protected function assertApiStatus(int $expectedStatus, callable $fn, string $case = ''): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame(
                $expectedStatus,
                $e->httpStatus(),
                ($case !== '' ? $case . ': ' : '') . 'неожиданный HTTP-статус: ' . $e->getMessage(),
            );

            return $e;
        }

        $this->fail(sprintf(
            '%sожидалась ApiException с HTTP %d, но исключение не выброшено',
            $case !== '' ? $case . ': ' : '',
            $expectedStatus,
        ));
    }

    /**
     * Канвас, созданный вне createCanvas() (например, самим контроллером),
     * тоже должен уйти в очистку tearDown.
     */
    protected function trackCanvasId(string $canvasId): void
    {
        $this->createdCanvasIds[] = $canvasId;
    }

    /**
     * Пользователь, созданный вне createUser() (например, AuthController::register),
     * тоже должен уйти в очистку tearDown.
     */
    protected function trackUserId(string $userId): void
    {
        $this->createdUserIds[] = $userId;
    }

    protected function assertIso8601(mixed $value, string $field): void
    {
        $this->assertIsString($value, $field . ' должен быть строкой');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?[+-]\d{2}:\d{2}$/',
            $value,
            $field . ' должен быть в ISO 8601: ' . $value,
        );
    }

    /**
     * @param class-string $class
     */
    protected function service(string $class): object
    {
        return $this->container->get($class);
    }

    protected function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
