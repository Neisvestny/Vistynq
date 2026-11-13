<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Controllers\TagController;
use Vistynq\Http\Response;

/**
 * CRUD пер-канвасных тегов (docs/ROADMAP.md R13, docs/openapi.yaml → tags):
 * 200-массив/201/200 Tag/204 без тела, 409 на дубль (citext, без учёта
 * регистра), 403/404 по правам канваса-владельца тега, каскадное снятие
 * note_tags при удалении.
 */
final class TagCrudTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $editorId;
    private string $viewerId;
    private string $strangerId;
    private string $canvasId;
    private string $noteId;
    private string $tagId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Теги');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');

        $this->noteId = $this->createNote($this->canvasId, $this->ownerId, 'Заметка с тегом');
        $this->tagId = $this->createTag($this->canvasId, 'метка');
    }

    public function testListReturnsFlatTags(): void
    {
        $tags = $this->listAs($this->ownerId);

        $this->assertCount(1, $tags);
        $this->assertSame(
            ['id', 'name', 'color'],
            array_keys($tags[0]),
            'Tag — плоский объект {id, name, color}',
        );
        $this->assertSame($this->tagId, $tags[0]['id']);
        $this->assertSame('метка', $tags[0]['name']);
        $this->assertSame('#7c3aed', $tags[0]['color']);
    }

    public function testListIsSortedByName(): void
    {
        $this->createTag($this->canvasId, 'zzz');
        $this->createTag($this->canvasId, 'aaa');

        $names = array_column($this->listAs($this->ownerId), 'name');

        // порядок вставки обратный — сортировка должна быть по имени
        $this->assertLessThan(
            array_search('zzz', $names, true),
            array_search('aaa', $names, true),
            'aaa должен идти раньше zzz: ' . implode(', ', $names),
        );
        $this->assertEqualsCanonicalizing(['aaa', 'метка', 'zzz'], $names);
    }

    public function testListRights(): void
    {
        $this->assertCount(1, $this->listAs($this->editorId));
        $this->assertCount(1, $this->listAs($this->viewerId), 'viewer видит список тегов');

        $this->assertApiStatus(404, fn (): array => $this->listAs($this->strangerId));
        $this->assertApiStatus(404, fn (): array => $this->listAs(null));

        $this->setCanvasAccessLevel($this->canvasId, 'public');
        $this->assertCount(1, $this->listAs(null), 'аноним на публичном канвасе видит теги');
    }

    public function testCreateReturnsTagAndTrimsName(): void
    {
        $response = $this->createTagAs($this->ownerId, ['name' => '  Новая  ', 'color' => '#ff0000']);

        $this->assertSame(201, $response->status());
        $tag = $this->json($response);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $tag['id'],
        );
        $this->assertSame('Новая', $tag['name'], 'имя обрезается по краям');
        $this->assertSame('#ff0000', $tag['color']);
        $this->assertContains('Новая', array_column($this->listAs($this->ownerId), 'name'));
    }

    public function testCreateUsesDefaultColor(): void
    {
        $tag = $this->json($this->createTagAs($this->ownerId, ['name' => 'без цвета']));

        $this->assertSame('#7c3aed', $tag['color']);
    }

    public function testCreateEnforcesRolesAndVerification(): void
    {
        $unverified = $this->createUser(verified: false);

        $this->assertApiStatus(403, fn (): Response => $this->createTagAs($this->viewerId, ['name' => 'x']));
        $this->assertApiStatus(404, fn (): Response => $this->createTagAs($this->strangerId, ['name' => 'x']));

        $e = $this->assertApiStatus(403, fn (): Response => $this->createTagAs($unverified, ['name' => 'x']));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testCreateValidation(): void
    {
        $cases = [
            'нет поля' => [],
            'пустая строка' => ['name' => ''],
            'только пробелы' => ['name' => '   '],
            'длиннее 50' => ['name' => str_repeat('т', 51)],
            'не строка' => ['name' => ['массив']],
            'null' => ['name' => null],
            'цвет без # ' => ['name' => 'c1', 'color' => 'ff0000'],
            'короткий hex' => ['name' => 'c2', 'color' => '#fff'],
            'не hex' => ['name' => 'c3', 'color' => 'red'],
            'цвет не строка' => ['name' => 'c4', 'color' => 123],
        ];

        foreach ($cases as $label => $body) {
            $this->assertApiStatus(
                422,
                fn (): Response => $this->createTagAs($this->ownerId, $body),
                $label,
            );
        }
    }

    public function testCreateDuplicateIs409IgnoringCase(): void
    {
        $e = $this->assertApiStatus(
            409,
            fn (): Response => $this->createTagAs($this->ownerId, ['name' => 'МЕТКА']),
        );

        $this->assertSame('CONFLICT', $e->errorCode());
        $this->assertArrayHasKey('name', $e->errorDetails());
        $this->assertSame(1, $this->countRows('SELECT count(*) FROM tags WHERE canvas_id = :id', ['id' => $this->canvasId]));
    }

    public function testUpdateRenameAndRecolorVisibleOnAllNotes(): void
    {
        $this->attachTag($this->noteId, $this->tagId);

        $response = $this->patchTagAs($this->editorId, ['name' => 'Новое имя', 'color' => '#00ff00']);

        $this->assertSame(200, $response->status());
        $tag = $this->json($response);
        $this->assertSame($this->tagId, $tag['id']);
        $this->assertSame('Новое имя', $tag['name']);
        $this->assertSame('#00ff00', $tag['color']);

        // переименование отражается на заметках без отдельных UPDATE —
        // заметки видят тег через join note_tags
        $graph = $this->canvasController->graph($this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/graph",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->ownerId,
        ));
        $nodes = $this->json($graph)['nodes'];
        $this->assertSame(['Новое имя'], $nodes[0]['tags']);
    }

    public function testUpdateWithEmptyBodyIsNoOp(): void
    {
        $tag = $this->json($this->patchTagAs($this->ownerId, []));

        $this->assertSame($this->tagId, $tag['id']);
        $this->assertSame('метка', $tag['name']);
        $this->assertSame('#7c3aed', $tag['color']);
    }

    public function testUpdateValidation(): void
    {
        $cases = [
            'пустое имя' => ['name' => ''],
            'пробелы' => ['name' => '  '],
            'длиннее 50' => ['name' => str_repeat('д', 51)],
            'null' => ['name' => null],
            'битый цвет' => ['color' => 'purple'],
            'цвет без #' => ['color' => 'abc123'],
        ];

        foreach ($cases as $label => $body) {
            $this->assertApiStatus(422, fn (): Response => $this->patchTagAs($this->ownerId, $body), $label);
        }

        $row = $this->tagRow($this->tagId);
        $this->assertSame('метка', $row['name'], 'некорректный ввод не должен менять тег');
        $this->assertSame('#7c3aed', $row['color']);
    }

    public function testUpdateDuplicateNameIs409(): void
    {
        $second = $this->createTag($this->canvasId, 'второй');

        $e = $this->assertApiStatus(
            409,
            fn (): Response => $this->patchTagAs($this->ownerId, ['name' => 'Метка'], $second),
        );

        $this->assertSame('CONFLICT', $e->errorCode());
        $this->assertSame('второй', $this->tagRow($second)['name'], 'дубль не должен перезаписать тег');
    }

    public function testUpdateRights(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->patchTagAs($this->viewerId, ['name' => 'x']));
        $this->assertApiStatus(404, fn (): Response => $this->patchTagAs($this->strangerId, ['name' => 'x']));
        $this->assertApiStatus(404, fn (): Response => $this->patchTagAs($this->ownerId, ['name' => 'x'], $this->uuid()));
        $this->assertSame('метка', $this->tagRow($this->tagId)['name'], 'неудачные вызовы не меняют тег');
    }

    public function testDeleteRemovesTagAndAllItsNoteTags(): void
    {
        $secondNote = $this->createNote($this->canvasId, $this->ownerId, 'Вторая');
        $this->attachTag($this->noteId, $this->tagId);
        $this->attachTag($secondNote, $this->tagId);
        $this->assertSame(2, $this->countRows('SELECT count(*) FROM note_tags WHERE tag_id = :id', ['id' => $this->tagId]));

        $response = $this->deleteTagAs($this->ownerId);

        $this->assertSame(204, $response->status());
        $this->assertSame('', $response->body(), '204 обязан приходить без тела');

        $this->assertNull($this->tagRow($this->tagId), 'тег удаляется (hard delete)');
        $this->assertSame(
            0,
            $this->countRows('SELECT count(*) FROM note_tags WHERE tag_id = :id', ['id' => $this->tagId]),
            'note_tags уходят каскадом — тег снят со всех заметок',
        );
        $this->assertSame([], array_column($this->listAs($this->ownerId), 'name'));
    }

    public function testDeleteRights(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->deleteTagAs($this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->deleteTagAs($this->strangerId));
        $this->assertApiStatus(404, fn (): Response => $this->deleteTagAs($this->ownerId, $this->uuid()));

        $this->assertNotNull($this->tagRow($this->tagId), 'неудачное удаление не должно трогать тег');
    }

    public function testFailedManifestPatchLeavesNoteTagsUntouched(): void
    {
        $this->attachTag($this->noteId, $this->tagId);
        $foreignCanvas = $this->createCanvas($this->strangerId, 'Чужой');
        $foreignTag = $this->createTag($foreignCanvas, 'чужой');

        // валидация падает до транзакции — частичной синхронизации быть не должно
        $this->assertApiStatus(422, fn (): Response => $this->noteController->update($this->request(
            'PATCH',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
            ['tagIds' => [$foreignTag]],
            [],
            [],
            $this->ownerId,
        )));

        $this->assertSame(
            1,
            $this->countRows('SELECT count(*) FROM note_tags WHERE note_id = :id', ['id' => $this->noteId]),
            'неудачный PATCH не должен оставлять частичных note_tags',
        );
    }

    public function testManifestPatchSyncsTagIds(): void
    {
        $another = $this->createTag($this->canvasId, 'ещё');

        $detail = $this->json($this->noteController->update($this->request(
            'PATCH',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
            ['tagIds' => [$this->tagId, $another]],
            [],
            [],
            $this->ownerId,
        )));

        // порядок tagIds в ответе — по имени тега (ORDER BY t.name), сверяем как множество
        $expected = [$this->tagId, $another];
        $actual = $detail['tagIds'];
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(
            2,
            $this->countRows('SELECT count(*) FROM note_tags WHERE note_id = :id', ['id' => $this->noteId]),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAs(?string $userId): array
    {
        $response = $this->tagController()->index($this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/tags",
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
     * @param array<string, mixed> $body
     */
    private function createTagAs(string $userId, array $body): Response
    {
        return $this->tagController()->create($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/tags",
            ['id' => $this->canvasId],
            $body,
            [],
            [],
            $userId,
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchTagAs(string $userId, array $body, ?string $tagId = null): Response
    {
        $id = $tagId ?? $this->tagId;

        return $this->tagController()->update($this->request(
            'PATCH',
            "/api/v1/tags/{$id}",
            ['id' => $id],
            $body,
            [],
            [],
            $userId,
        ));
    }

    private function deleteTagAs(string $userId, ?string $tagId = null): Response
    {
        $id = $tagId ?? $this->tagId;

        return $this->tagController()->delete($this->request(
            'DELETE',
            "/api/v1/tags/{$id}",
            ['id' => $id],
            null,
            [],
            [],
            $userId,
        ));
    }

    private function tagController(): TagController
    {
        return $this->service(TagController::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tagRow(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tags WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function attachTag(string $noteId, string $tagId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO note_tags (note_id, tag_id) VALUES (:note_id, :tag_id) ON CONFLICT DO NOTHING'
        )->execute(['note_id' => $noteId, 'tag_id' => $tagId]);
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
}
