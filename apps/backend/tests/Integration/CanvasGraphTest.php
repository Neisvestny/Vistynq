<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\NoteService;

/**
 * Счётчик SQL-запросов: нужен, чтобы поймать N+1 в GET /canvases/{id}/graph
 * (см. docs/architecture.md §4 — логика в сервисах, SQL в репозиториях).
 */
final class SqlCountingStatement extends PDOStatement
{
    public static int $count = 0;

    public function execute(?array $params = null): bool
    {
        self::$count++;

        return parent::execute($params);
    }
}

final class CanvasGraphTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $editorId;
    private string $strangerId;
    private string $canvasId;
    private string $otherCanvasId;
    private string $noteA;
    private string $noteB;
    private string $noteC;
    private string $purgedNote;
    private string $foreignNote;
    private string $tagId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Граф');
        $this->otherCanvasId = $this->createCanvas($this->strangerId, 'Чужой канвас');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');

        $this->noteA = $this->createNote($this->canvasId, $this->ownerId, 'Заметка А');
        $this->noteB = $this->createNote($this->canvasId, $this->ownerId, 'Заметка Б');
        $this->noteC = $this->createNote($this->canvasId, $this->editorId, 'Заметка С');
        $this->purgedNote = $this->createNote($this->canvasId, $this->ownerId, 'Стираемая');
        $this->foreignNote = $this->createNote($this->otherCanvasId, $this->strangerId, 'Чужая заметка');

        // С уходит в soft-delete: нодой не становится, но маркер ссылки обязан жить
        $this->db->pdo()->prepare('UPDATE notes SET deleted_at = now() WHERE id = :id')
            ->execute(['id' => $this->noteC]);

        $this->tagId = $this->createTag($this->canvasId, 'мой-тег');
        $foreignTagId = $this->createTag($this->otherCanvasId, 'чужой-тег');
        // Чужой тег, привязанный к заметке в обход API: graph не должен его показать
        $this->attachTag($this->noteA, $this->tagId);
        $this->attachTag($this->noteA, $foreignTagId);

        $this->insertDirectLink($this->noteA, $this->noteB, $this->ownerId);
        $this->insertDirectLink($this->noteA, $this->foreignNote, $this->ownerId);

        $this->insertIndirectLink($this->noteA, $this->noteB, '[[Заметка Б]]', true);
        $this->insertIndirectLink($this->noteA, $this->noteC, '[[Заметка С]]', true);
        $this->insertIndirectLink($this->noteA, $this->foreignNote, '[[Чужая заметка]]', true);
        $this->insertIndirectLink($this->noteA, $this->purgedNote, '[[Стираемая]]', true);

        // Purge: to_note_id обязан стать NULL (миграция 0003, ON DELETE SET NULL)
        $this->service(NoteService::class)->purge($this->purgedNote, $this->ownerId);
    }

    public function testGraphReturnsNodesEdgesAndTags(): void
    {
        $data = $this->graphAs($this->ownerId);

        $this->assertSame($this->canvasId, $data['canvas']['id']);
        $this->assertSame($this->ownerId, $data['canvas']['ownerId']);
        $this->assertSame('private', $data['canvas']['access']['level']);
        $this->assertSame('owner', $data['canvas']['access']['myRole']);
        $this->assertNull($data['canvas']['access']['publicSlug']);
        $this->assertIso8601($data['canvas']['createdAt'], 'canvas.createdAt');

        // С (soft-deleted) и стёртая — не ноды; чужая заметка вообще не в этом канвасе
        $nodeIds = array_column($data['nodes'], 'id');
        sort($nodeIds);
        $expected = [$this->noteA, $this->noteB];
        sort($expected);
        $this->assertSame($expected, $nodeIds);

        $nodeA = $this->node($data, $this->noteA);
        $this->assertSame('Заметка А', $nodeA['title']);
        $this->assertSame(3, (int) $nodeA['importance']);
        $this->assertTrue($nodeA['isRelevant']);
        $this->assertSame(220, (int) $nodeA['width']);
        $this->assertNull($nodeA['deletedAt']);
        $this->assertSame($this->ownerId, $nodeA['authorId']);
        // утечка имён чужих тегов через note_tags JOIN tags недопустима
        $this->assertSame(['мой-тег'], $nodeA['tags']);

        $this->assertCount(1, $data['tags']);
        $this->assertSame($this->tagId, $data['tags'][0]['id']);
        $this->assertSame('мой-тег', $data['tags'][0]['name']);
    }

    public function testDirectEdgeRequiresBothEndsVisible(): void
    {
        $data = $this->graphAs($this->ownerId);

        $direct = array_values(array_filter($data['edges'], static fn (array $e): bool => $e['type'] === 'direct'));
        $this->assertCount(1, $direct, 'прямая ссылка на чужую заметку должна исчезнуть целиком');
        $this->assertSame($this->noteA, $direct[0]['from']);
        $this->assertSame($this->noteB, $direct[0]['to']);
        $this->assertSame('ok', $direct[0]['state']);
        $this->assertNull($direct[0]['label']);
    }

    public function testIndirectEdgesDistinguishRemovedAndHiddenTargets(): void
    {
        $data = $this->graphAs($this->ownerId);

        $indirect = array_values(array_filter($data['edges'], static fn (array $e): bool => $e['type'] === 'indirect'));
        $this->assertCount(3, $indirect, 'ссылка на чужой канвас исключается, остальные три обязаны прийти');

        $ok = array_values(array_filter($indirect, static fn (array $e): bool => $e['state'] === 'ok'));
        $this->assertCount(1, $ok);
        $this->assertSame($this->noteB, $ok[0]['to']);
        $this->assertNull($ok[0]['label']);

        $removed = array_values(array_filter($indirect, static fn (array $e): bool => $e['state'] === 'removed'));
        $this->assertCount(2, $removed, 'soft-deleted цель и purged цель — «ссылка удалена»');
        foreach ($removed as $edge) {
            $this->assertNull($edge['to']);
            $this->assertIsString($edge['label']);
            $this->assertStringStartsWith('[[', $edge['label']);
        }

        $labels = array_column($removed, 'label');
        sort($labels);
        $this->assertSame(['[[Заметка С]]', '[[Стираемая]]'], $labels);

        foreach ($indirect as $edge) {
            $this->assertNotSame($this->foreignNote, $edge['to'], 'чужая заметка не должна утекать в граф');
            $this->assertNotSame($this->noteC, $edge['to'], 'soft-deleted цель не должна быть нодой');
        }
    }

    public function testEditorSeesGraphWithEditorRole(): void
    {
        $data = $this->graphAs($this->editorId);

        $this->assertSame('editor', $data['canvas']['access']['myRole']);
        $this->assertCount(2, $data['nodes']);
    }

    public function testStrangerGets404NotPartialGraph(): void
    {
        $this->assertApiStatus(404, fn (): Response => $this->graphAs($this->strangerId));
    }

    public function testAnonymousOnPrivateCanvasGets404(): void
    {
        $this->assertApiStatus(404, fn (): Response => $this->graphAs(null));
    }

    public function testAnonymousOnPublicLinkCanvasGetsViewerGraph(): void
    {
        $this->setCanvasAccessLevel($this->canvasId, 'public_link');

        $data = $this->graphAs(null);

        $this->assertSame('viewer', $data['canvas']['access']['myRole']);
        $this->assertCount(2, $data['nodes']);
    }

    public function testInvalidBearerOnOptionalRouteIs401NotAnonym(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $request = $this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/graph",
            ['id' => $this->canvasId],
            null,
            [],
            ['authorization' => 'Bearer not-a-jwt'],
        );

        $this->assertApiStatus(401, fn (): Response => $middleware->process(
            $request,
            static fn (Request $r): Response => Response::json(['reached' => true]),
        ));
    }

    public function testExpiredBearerOnOptionalRouteIs401(): void
    {
        $expired = \Firebase\JWT\JWT::encode([
            'sub' => $this->ownerId,
            'email' => 'owner@example.local',
            'iat' => time() - 7200,
            'exp' => time() - 60,
        ], $this->config->jwtSecret(), 'HS256');

        $middleware = new AuthMiddleware($this->jwt);
        $request = $this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/graph",
            ['id' => $this->canvasId],
            null,
            [],
            ['authorization' => 'Bearer ' . $expired],
        );

        $this->assertApiStatus(401, fn (): Response => $middleware->process(
            $request,
            static fn (Request $r): Response => Response::json(['reached' => true]),
        ));
    }

    public function testMissingBearerOnOptionalRouteBecomesAnonymous(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $request = $this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/graph",
            ['id' => $this->canvasId],
        );

        $response = $middleware->process(
            $request,
            static fn (Request $r): Response => Response::json(['userId' => AuthMiddleware::optionalUserId($r)]),
        );

        $this->assertSame(200, $response->status());
        $this->assertNull($this->json($response)['userId']);
    }

    public function testGraphQueryCountDoesNotGrowWithNotes(): void
    {
        $pdo = $this->db->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['SqlCountingStatement']);

        SqlCountingStatement::$count = 0;
        $this->graphAs($this->ownerId);
        $before = SqlCountingStatement::$count;

        for ($i = 0; $i < 10; $i++) {
            $this->createNote($this->canvasId, $this->ownerId, 'Лишняя заметка ' . $i);
        }

        SqlCountingStatement::$count = 0;
        $this->graphAs($this->ownerId);
        $after = SqlCountingStatement::$count;

        $this->assertSame($before, $after, 'graph не должен выполнять запросы на каждую заметку (N+1)');
        $this->assertLessThanOrEqual(8, $after, 'ожидалось не больше 8 запросов на граф, получено ' . $after);
    }

    /**
     * @return array<string, mixed>
     */
    private function graphAs(?string $userId): array
    {
        $response = $this->canvasController->graph($this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/graph",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $userId,
        ));

        $this->assertSame(200, $response->status());

        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function node(array $data, string $noteId): array
    {
        foreach ($data['nodes'] as $node) {
            if ($node['id'] === $noteId) {
                return $node;
            }
        }

        $this->fail('нода ' . $noteId . ' не найдена в графе');

        return [];
    }

    private function attachTag(string $noteId, string $tagId): void
    {
        $this->db->pdo()->prepare('INSERT INTO note_tags (note_id, tag_id) VALUES (:note_id, :tag_id) ON CONFLICT DO NOTHING')
            ->execute(['note_id' => $noteId, 'tag_id' => $tagId]);
    }

    private function insertDirectLink(string $from, string $to, string $createdBy): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO direct_links (from_note_id, to_note_id, created_by) VALUES (:from, :to, :by)'
        )->execute(['from' => $from, 'to' => $to, 'by' => $createdBy]);
    }

    private function insertIndirectLink(string $from, ?string $to, string $rawTarget, bool $resolved): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO indirect_links (from_note_id, to_note_id, raw_target, resolved) VALUES (:from, :to, :raw, :resolved)'
        )->execute(['from' => $from, 'to' => $to, 'raw' => $rawTarget, 'resolved' => $resolved]);
    }
}
