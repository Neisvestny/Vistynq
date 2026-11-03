<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Response;
use Vistynq\Services\NoteService;

/**
 * CRUD канваса (docs/ROADMAP.md R11, docs/openapi.yaml → Canvas):
 * 201 с полной сущностью, 200 Canvas на PATCH, 204 без тела на DELETE,
 * 403 — виден, но роли не хватает; 404 — не виден вовсе.
 */
final class CanvasCrudTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $editorId;
    private string $viewerId;
    private string $strangerId;
    private string $canvasId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Мой канвас');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');
    }

    public function testCreateReturnsFullCanvasEntity(): void
    {
        $response = $this->canvasController->create($this->request(
            'POST',
            '/api/v1/canvases',
            [],
            ['title' => '  Новый канвас  '],
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(201, $response->status());
        $data = $this->json($response);

        $this->assertArrayHasKey('id', $data);
        $this->assertSame('Новый канвас', $data['title'], 'заголовок обрезается по краям');
        $this->assertSame($this->ownerId, $data['ownerId']);
        $this->assertSame('private', $data['access']['level']);
        $this->assertNull($data['access']['publicSlug']);
        $this->assertSame('owner', $data['access']['myRole']);
        $this->assertIso8601($data['createdAt'], 'canvas.createdAt');

        // созданный канвас должен попасть в tearDown-очистку
        $this->trackCanvasId((string) $data['id']);
    }

    public function testCreateRequiresVerifiedEmail(): void
    {
        $unverified = $this->createUser(verified: false);

        $e = $this->assertApiStatus(403, fn (): Response => $this->canvasController->create($this->request(
            'POST',
            '/api/v1/canvases',
            [],
            ['title' => 'Канвас'],
            [],
            [],
            $unverified,
        )));

        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testCreateValidatesTitle(): void
    {
        $cases = [
            'нет поля' => [],
            'пустая строка' => ['title' => ''],
            'только пробелы' => ['title' => '   '],
            'слишком длинный' => ['title' => str_repeat('а', 201)],
            'не строка' => ['title' => ['массив']],
        ];

        foreach ($cases as $label => $body) {
            $this->assertApiStatus(
                422,
                fn (): Response => $this->canvasController->create($this->request(
                    'POST',
                    '/api/v1/canvases',
                    [],
                    $body,
                    [],
                    [],
                    $this->ownerId,
                )),
                $label,
            );
        }
    }

    public function testIndexReturnsCanvasListWithRoles(): void
    {
        $ownerView = $this->json($this->canvasController->index(
            $this->request('GET', '/api/v1/canvases', [], null, [], [], $this->ownerId),
        ));
        $this->assertContains($this->canvasId, array_column($ownerView['items'], 'id'));
        $own = $this->itemById($ownerView, $this->canvasId);
        $this->assertSame('owner', $own['access']['myRole']);
        $this->assertSame('private', $own['access']['level']);

        $editorView = $this->json($this->canvasController->index(
            $this->request('GET', '/api/v1/canvases', [], null, [], [], $this->editorId),
        ));
        $this->assertSame('editor', $this->itemById($editorView, $this->canvasId)['access']['myRole']);

        $strangerView = $this->json($this->canvasController->index(
            $this->request('GET', '/api/v1/canvases', [], null, [], [], $this->strangerId),
        ));
        $this->assertNotContains($this->canvasId, array_column($strangerView['items'], 'id'));
    }

    public function testIndexCountsLiveNotesOnly(): void
    {
        $this->createNote($this->canvasId, $this->ownerId, 'Первая');
        $deleted = $this->createNote($this->canvasId, $this->ownerId, 'Удаляемая');
        $this->service(NoteService::class)->softDelete($deleted, $this->ownerId);

        $view = $this->json($this->canvasController->index(
            $this->request('GET', '/api/v1/canvases', [], null, [], [], $this->ownerId),
        ));

        $this->assertSame(1, $this->itemById($view, $this->canvasId)['noteCount'], 'soft-deleted не считаются');

        // созданный через контроллер канвас без заметок — noteCount = 0
        $created = $this->json($this->canvasController->create($this->request(
            'POST',
            '/api/v1/canvases',
            [],
            ['title' => 'Пустой'],
            [],
            [],
            $this->ownerId,
        )));
        $this->trackCanvasId((string) $created['id']);
        $this->assertArrayNotHasKey('noteCount', $created, 'POST /canvases не считает заметки');
    }

    public function testUpdateTitleByEditorReturnsCanvas(): void
    {
        $response = $this->canvasController->update($this->request(
            'PATCH',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            ['title' => 'Переименованный'],
            [],
            [],
            $this->editorId,
        ));

        $this->assertSame(200, $response->status());
        $data = $this->json($response);
        $this->assertSame($this->canvasId, $data['id']);
        $this->assertSame('Переименованный', $data['title']);
        $this->assertSame('editor', $data['access']['myRole']);
        $this->assertSame($this->ownerId, $data['ownerId']);
    }

    public function testUpdateWithoutTitleIsNoOp(): void
    {
        $response = $this->canvasController->update($this->request(
            'PATCH',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            [],
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(200, $response->status());
        $this->assertSame('Мой канвас', $this->json($response)['title']);
    }

    public function testUpdateValidatesTitle(): void
    {
        $cases = [
            'пустая строка' => ['title' => ''],
            'только пробелы' => ['title' => '  '],
            'слишком длинный' => ['title' => str_repeat('б', 201)],
            'null' => ['title' => null],
        ];

        foreach ($cases as $label => $body) {
            $this->assertApiStatus(
                422,
                fn (): Response => $this->canvasController->update($this->request(
                    'PATCH',
                    "/api/v1/canvases/{$this->canvasId}",
                    ['id' => $this->canvasId],
                    $body,
                    [],
                    [],
                    $this->ownerId,
                )),
                $label,
            );
        }
    }

    public function testUpdateByViewerIs403AndByStrangerIs404(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->canvasController->update($this->request(
            'PATCH',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            ['title' => 'X'],
            [],
            [],
            $this->viewerId,
        )));

        $this->assertApiStatus(404, fn (): Response => $this->canvasController->update($this->request(
            'PATCH',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            ['title' => 'X'],
            [],
            [],
            $this->strangerId,
        )));
    }

    public function testDeleteByOwnerReturns204WithEmptyBody(): void
    {
        $response = $this->canvasController->delete($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(204, $response->status());
        $this->assertSame('', $response->body(), '204 обязан приходить без тела');
        $this->assertNull($this->canvasRow($this->canvasId));
    }

    public function testDeleteByEditorIs403AndByStrangerIs404(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->canvasController->delete($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->editorId,
        )));

        $this->assertApiStatus(404, fn (): Response => $this->canvasController->delete($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->strangerId,
        )));

        $this->assertNotNull($this->canvasRow($this->canvasId), 'неудачное удаление не должно трогать канвас');
    }

    public function testDeleteCascadesAndRemovesPolymorphicOrphans(): void
    {
        $noteA = $this->createNote($this->canvasId, $this->ownerId, 'Первая');
        $noteB = $this->createNote($this->canvasId, $this->ownerId, 'Вторая');
        $this->createTag($this->canvasId, 'тег');

        $pdo = $this->db->pdo();
        // инвайты: один на канвас, один на его заметку (полиморфный resource_id без FK)
        $pdo->prepare(
            'INSERT INTO invitations (resource_type, resource_id, email, role)
             VALUES (\'canvas\', :rid, \'a@example.local\', \'editor\')'
        )->execute(['rid' => $this->canvasId]);
        $pdo->prepare(
            'INSERT INTO invitations (resource_type, resource_id, email, role)
             VALUES (\'note\', :rid, \'b@example.local\', \'viewer\')'
        )->execute(['rid' => $noteA]);

        // tombstone переживает заметку по построению (deleted_notes без FK на notes)
        $this->service(NoteService::class)->purge($noteB, $this->ownerId);
        $this->assertSame(
            1,
            $this->countRows('SELECT count(*) FROM deleted_notes WHERE canvas_id = :id', ['id' => $this->canvasId]),
            'предусловие: tombstone создан',
        );

        $this->canvasController->delete($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertNull($this->canvasRow($this->canvasId));
        $this->assertSame(0, $this->countRows('SELECT count(*) FROM notes WHERE canvas_id = :id', ['id' => $this->canvasId]));
        $this->assertSame(0, $this->countRows('SELECT count(*) FROM tags WHERE canvas_id = :id', ['id' => $this->canvasId]));
        $this->assertSame(0, $this->countRows('SELECT count(*) FROM deleted_notes WHERE canvas_id = :id', ['id' => $this->canvasId]));
        $this->assertSame(
            0,
            $this->countRows(
                'SELECT count(*) FROM invitations WHERE resource_type = \'canvas\' AND resource_id = :id'
                . ' OR (resource_type = \'note\' AND resource_id IN (SELECT id FROM notes WHERE canvas_id = :id))',
                ['id' => $this->canvasId],
            ),
            'инвайты канваса и его заметок не должны осиротеть',
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function itemById(array $payload, string $id): array
    {
        foreach ($payload['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        $this->fail('канвас ' . $id . ' не найден в списке');

        return [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function canvasRow(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM canvases WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
