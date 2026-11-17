<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Config\EnvLoader;
use Vistynq\Controllers\AttachmentController;
use Vistynq\Controllers\AuthController;
use Vistynq\Controllers\CanvasController;
use Vistynq\Controllers\NoteController;
use Vistynq\Controllers\ProfileController;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Repositories\AttachmentRepositoryInterface;
use Vistynq\Repositories\CanvasRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Services\AttachmentService;
use Vistynq\Services\AvatarService;
use Vistynq\Services\JwtService;
use Vistynq\Services\Storage\S3Adapter;
use Vistynq\Services\Storage\StorageInterface;
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
    protected StorageInterface $storage;
    protected AttachmentRepositoryInterface $attachments;
    protected AttachmentService $attachmentService;
    protected AvatarService $avatarService;
    protected AttachmentController $attachmentController;
    protected ProfileController $profileController;
    protected AuthController $authController;

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdCanvasIds = [];

    /** @var list<string> */
    private array $storageKeys = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    /** null — ещё не проверяли, true/false — результат пробы MinIO. */
    private ?bool $storageAvailable = null;

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
        $this->storage = $this->container->get(StorageInterface::class);
        $this->attachments = $this->container->get(AttachmentRepositoryInterface::class);
        $this->attachmentService = $this->container->get(AttachmentService::class);
        $this->avatarService = $this->container->get(AvatarService::class);
        $this->attachmentController = $this->container->get(AttachmentController::class);
        $this->profileController = $this->container->get(ProfileController::class);
        $this->authController = $this->container->get(AuthController::class);
    }

    protected function tearDown(): void
    {
        // setUp мог завершиться skip'ом (нет pdo_pgsql) — тогда чистить нечего
        if (!isset($this->db)) {
            return;
        }

        $pdo = $this->db->pdo();
        // Вложения (R17): attachments.note_id — ON DELETE SET NULL, поэтому
        // сиротские строки удаляем явно до канвасов/пользователей, а объекты
        // в S3 — после коммита БД.
        $storageKeys = array_merge($this->storageKeys, $this->attachmentKeysForCreatedEntities());

        if ($this->createdUserIds !== []) {
            $in = implode(', ', array_fill(0, count($this->createdUserIds), '?'));
            $pdo->prepare("DELETE FROM attachments WHERE uploader_id IN ({$in})")
                ->execute($this->createdUserIds);
        }
        if ($this->createdCanvasIds !== []) {
            $in = implode(', ', array_fill(0, count($this->createdCanvasIds), '?'));
            $pdo->prepare(
                "DELETE FROM attachments a
                       USING notes n
                       WHERE a.note_id = n.id
                         AND n.canvas_id IN ({$in})",
            )->execute($this->createdCanvasIds);
        }

        // notes.author_id и direct_links.created_by без ON DELETE, поэтому
        // сначала канвасы (каскад уносит notes/links/tags/access), потом юзеры.
        foreach ($this->createdCanvasIds as $canvasId) {
            $this->canvases->delete($canvasId);
        }
        foreach ($this->createdUserIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }

        if ($storageKeys !== []) {
            try {
                $this->storage->deleteMany(array_values(array_unique($storageKeys)));
            } catch (Throwable $e) {
                // Очистка теста не должна маскировать результат проверок.
                error_log('[tests] storage cleanup failed: ' . $e->getMessage());
            }
        }

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->createdCanvasIds = [];
        $this->createdUserIds = [];
        $this->storageKeys = [];
        $this->temporaryFiles = [];
    }

    /**
     * Все s3_key вложений созданных тестом пользователей/канвасов — до
     * удаления строк (R17).
     *
     * @return list<string>
     */
    private function attachmentKeysForCreatedEntities(): array
    {
        $keys = [];
        $pdo = $this->db->pdo();

        if ($this->createdUserIds !== []) {
            $in = implode(', ', array_fill(0, count($this->createdUserIds), '?'));
            $stmt = $pdo->prepare("SELECT s3_key FROM attachments WHERE uploader_id IN ({$in})");
            $stmt->execute($this->createdUserIds);
            $keys = array_merge($keys, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($this->createdCanvasIds !== []) {
            $in = implode(', ', array_fill(0, count($this->createdCanvasIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT a.s3_key
                   FROM attachments a
                   JOIN notes n ON n.id = a.note_id
                  WHERE n.canvas_id IN ({$in})",
            );
            $stmt->execute($this->createdCanvasIds);
            $keys = array_merge($keys, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return array_values(array_unique(array_map('strval', $keys)));
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

    /**
     * Сообщения Mailpit для адреса (SMTP-приёмник dev/CI). Mailpit отдаёт
     * новые письма первыми — на этом порядке держатся тесты email-change.
     *
     * @return list<array<string, mixed>>
     */
    protected function mailpitMessages(string $email): array
    {
        $api = rtrim((string) (getenv('MAILPIT_API') ?: 'http://127.0.0.1:8025'), '/');

        $search = @file_get_contents(
            $api . '/api/v1/search?query=' . rawurlencode('to:' . $email) . '&limit=50',
        );
        $this->assertIsString(
            $search,
            'Mailpit API недоступен на ' . $api . ' — нужен сервис mailpit (docker compose up -d mailpit)',
        );

        $decoded = json_decode($search, true);
        $messages = is_array($decoded) ? ($decoded['messages'] ?? []) : [];
        $this->assertNotSame([], $messages, 'письма не найдены в Mailpit для ' . $email);

        return array_values($messages);
    }

    protected function mailpitMessageText(string $id): string
    {
        $api = rtrim((string) (getenv('MAILPIT_API') ?: 'http://127.0.0.1:8025'), '/');

        $raw = @file_get_contents($api . '/api/v1/message/' . rawurlencode($id));
        $this->assertIsString($raw, 'не удалось получить письмо ' . $id . ' из Mailpit');

        $message = json_decode($raw, true);

        return is_array($message) ? (string) ($message['Text'] ?? '') : '';
    }

    /**
     * Токен подтверждения из письма /verify-email?token=… для адреса.
     * Перебирает письма (новые первыми), чтобы не зависеть от соседних
     * писем-приглашений в том же ящике.
     */
    protected function fetchVerifyTokenFromMailpit(string $email): string
    {
        foreach ($this->mailpitMessages($email) as $message) {
            $id = (string) ($message['ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $body = $this->mailpitMessageText($id);
            if (preg_match('~verify-email\?token=([0-9a-f]{64})~', $body, $matches) === 1) {
                return $matches[1];
            }
        }

        $this->fail('в письмах Mailpit для ' . $email . ' нет ссылки /verify-email?token=…');
    }

    protected function userIdByEmail(string $email): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();

        return is_string($id) ? $id : null;
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
     * Проба реального MinIO (R17): тесты хранилища не подменяют адаптер
     * временной папкой. Если сервис/бакет недоступен — skip с понятным
     * сообщением, а не падение всех интеграционных тестов.
     */
    protected function requireStorage(): void
    {
        if ($this->storageAvailable === true) {
            return;
        }
        if ($this->storageAvailable === false) {
            $this->markTestSkipped('MinIO недоступен — storage-тесты пропущены');
        }

        $this->assertInstanceOf(
            S3Adapter::class,
            $this->storage,
            'интеграционные тесты R17 должны работать с реальным MinIO (STORAGE_DRIVER=s3), а не с LocalAdapter',
        );

        $key = 'tests/probe-' . bin2hex(random_bytes(6));
        $file = tempnam(sys_get_temp_dir(), 'vistynq-probe-');
        $this->assertNotFalse($file);
        file_put_contents($file, 'probe');

        try {
            $this->storage->putFile($key, $file, 'text/plain');
            $this->storageAvailable = $this->storage->exists($key);
        } catch (Throwable $e) {
            $this->storageAvailable = false;
            $this->markTestSkipped(
                'MinIO недоступен (' . $this->config->s3PublicEndpoint() . '): ' . $e->getMessage(),
            );
        } finally {
            @unlink($file);
            if ($this->storageAvailable === true) {
                $this->storage->delete($key);
            }
        }

        $this->assertTrue($this->storageAvailable, 'MinIO не сохранил пробный объект');
    }

    /**
     * $_FILES['file']-подобная структура с реальным временным файлом.
     *
     * @return array<string, mixed>
     */
    protected function uploadedFile(string $content, string $name = 'upload.bin'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'vistynq-upload-');
        $this->assertNotFalse($path);
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ];
    }

    /**
     * @param array<string, mixed>|null $file
     * @param array<string, string>     $fields
     */
    protected function requestWithFile(
        string $method,
        string $path,
        array $routeParams,
        ?array $file,
        ?string $authUserId = null,
        array $fields = [],
    ): Request {
        $attributes = ['multipart' => [
            'fields' => $fields,
            'files' => $file !== null ? ['file' => $file] : [],
        ]];
        if ($authUserId !== null) {
            $attributes['authUserId'] = $authUserId;
        }

        return new Request($method, $path, [], [], '', [], $routeParams, $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function attachmentRow(string $id): array
    {
        $row = $this->attachments->findById($id);
        $this->assertNotNull($row, 'строка attachments не найдена: ' . $id);
        $this->storageKeys[] = (string) $row['s3_key'];

        return $row;
    }

    protected function trackStorageKey(string $key): void
    {
        $this->storageKeys[] = $key;
    }

    protected function assertStorageMissing(string $key, string $case = ''): void
    {
        $this->assertFalse(
            $this->storage->exists($key),
            ($case !== '' ? $case . ': ' : '') . 'объект должен быть удалён из хранилища: ' . $key,
        );
    }

    /**
     * Скачивает объект по presigned/static URL — проверяет, что ссылка
     * действительно открывается (SigV4 подписан публичным Host).
     */
    protected function download(string $url): string
    {
        $content = @file_get_contents($url);
        $this->assertIsString($content, 'URL хранилища не открывается: ' . $url);

        return $content;
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
