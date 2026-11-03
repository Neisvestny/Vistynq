<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Response;

/**
 * Жизненный цикл заметки (docs/ROADMAP.md R12, docs/openapi.yaml → Note/NoteDetail):
 * валидация манифеста, тело + парсинг [[wikilinks]], позиции, soft delete,
 * restore и purge с tombstone.
 */
final class NoteLifecycleTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $editorId;
    private string $viewerId;
    private string $strangerId;
    private string $canvasId;
    private string $noteId;
    private string $tagId;
    private int $lastStatus = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Заметки');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');

        $this->noteId = $this->createNote($this->canvasId, $this->ownerId, 'Заметка А');
        $this->tagId = $this->createTag($this->canvasId, 'метка');
    }

    public function testCreateReturnsFullNoteEntity(): void
    {
        $response = $this->noteController->create($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/notes",
            ['id' => $this->canvasId],
            ['title' => '  Новая  ', 'x' => 12.5, 'y' => -3],
            [],
            [],
            $this->editorId,
        ));

        $this->assertSame(201, $response->status());
        $note = $this->json($response);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $note['id'],
        );
        $this->assertSame($this->canvasId, $note['canvasId']);
        $this->assertSame($this->editorId, $note['authorId']);
        $this->assertSame('Новая', $note['title']);
        $this->assertSame(3, $note['importance']);
        $this->assertTrue($note['isRelevant']);
        $this->assertSame([], $note['tagIds']);
        $this->assertEqualsWithDelta(12.5, $note['x'], 0.0001);
        $this->assertEqualsWithDelta(-3.0, $note['y'], 0.0001);
        $this->assertSame(220, $note['width']);
        $this->assertNull($note['deletedAt']);
        $this->assertIso8601($note['createdAt'], 'note.createdAt');
        $this->assertIso8601($note['updatedAt'], 'note.updatedAt');
    }

    public function testCreateUsesDefaultTitleAndPosition(): void
    {
        $note = $this->json($this->noteController->create($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/notes",
            ['id' => $this->canvasId],
            [],
            [],
            [],
            $this->ownerId,
        )));

        $this->assertSame('Без названия', $note['title']);
        $this->assertEqualsWithDelta(0.0, $note['x'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $note['y'], 0.0001);
    }

    public function testCreateEnforcesCanvasRightsAndVerification(): void
    {
        $unverified = $this->createUser(verified: false);

        $this->assertApiStatus(403, fn (): Response => $this->createNoteAs($this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->createNoteAs($this->strangerId));

        $e = $this->assertApiStatus(403, fn (): Response => $this->createNoteAs($unverified));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testCreateValidatesInput(): void
    {
        $cases = [
            'x не число' => ['x' => 'abc'],
            'y массив' => ['y' => []],
            'x NAN' => ['x' => NAN],
            'title длиннее 200' => ['title' => str_repeat('з', 201)],
        ];

        foreach ($cases as $label => $body) {
            $this->assertApiStatus(
                422,
                fn (): Response => $this->createNoteAs($this->ownerId, $body),
                $label,
            );
        }
    }

    public function testGetReturnsFlatNoteDetail(): void
    {
        $response = $this->noteController->show($this->request(
            'GET',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(200, $response->status());
        $detail = $this->json($response);

        // docs/api.md: NoteDetail — плоский объект, а не {note, access}
        $this->assertArrayNotHasKey('note', $detail);
        $this->assertArrayNotHasKey('access', $detail);
        $this->assertArrayHasKey('bodyMd', $detail);
        $this->assertSame('', $detail['bodyMd']);
        $this->assertSame('owner', $detail['myRole']);
        $this->assertSame($this->noteId, $detail['id']);
        $this->assertSame($this->canvasId, $detail['canvasId']);
        $this->assertSame([], $detail['tagIds']);
        $this->assertNull($detail['deletedAt']);
    }

    public function testAuthorWithoutCanvasGrantCanReadOwnNote(): void
    {
        // авторство даёт доступ к заметке даже без прав на канвас (docs/api.md)
        $authorId = $this->createUser();
        $noteId = $this->createNote($this->canvasId, $authorId, 'Чужая для канваса');

        $detail = $this->json($this->noteController->show($this->request(
            'GET',
            "/api/v1/notes/{$noteId}",
            ['id' => $noteId],
            null,
            [],
            [],
            $authorId,
        )));

        $this->assertSame('author', $detail['myRole']);
        $this->assertSame($authorId, $detail['authorId']);
    }

    public function testGetByStrangerIs404(): void
    {
        $this->assertApiStatus(404, fn (): Response => $this->noteController->show($this->request(
            'GET',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $this->strangerId,
        )));
    }

    public function testGetByAnonymousOnPrivateNoteIs404(): void
    {
        $this->assertApiStatus(404, fn (): Response => $this->noteController->show($this->request(
            'GET',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
        )));
    }

    public function testPatchManifestValidation(): void
    {
        $cases = [
            'importance выше диапазона' => ['importance' => 99],
            'importance ниже диапазона' => ['importance' => 0],
            'importance не целое' => ['importance' => 2.5],
            'isRelevant строкой' => ['isRelevant' => 'false'],
            'isRelevant числом' => ['isRelevant' => 1],
            'title пустой' => ['title' => ''],
            'title не строка' => ['title' => 7],
            'tagIds не массив' => ['tagIds' => 'nope'],
            'tagIds с мусором' => ['tagIds' => ['not-a-uuid']],
            'tagIds неизвестный тег' => ['tagIds' => [$this->uuid()]],
        ];

        foreach ($cases as $label => $body) {
            $this->assertApiStatus(422, fn (): Response => $this->patchManifest($body, $this->ownerId), $label);
        }
    }

    public function testPatchManifestRejectsForeignCanvasTag(): void
    {
        $foreignCanvas = $this->createCanvas($this->strangerId, 'Чужой');
        $foreignTag = $this->createTag($foreignCanvas, 'чужая метка');

        $e = $this->assertApiStatus(
            422,
            fn (): Response => $this->patchManifest(['tagIds' => [$foreignTag]], $this->ownerId),
        );

        $this->assertSame('VALIDATION_FAILED', $e->errorCode());
        $this->assertStringContainsString('канвас', implode(' ', $e->errorDetails()['tagIds'] ?? []));
    }

    public function testPatchManifestAppliesAndSyncsTags(): void
    {
        $detail = $this->json($this->patchManifest([
            'title' => 'Обновлённая',
            'importance' => 5,
            'isRelevant' => false,
            'tagIds' => [$this->tagId],
        ], $this->editorId));

        $this->assertSame(200, $this->lastStatus);
        $this->assertSame('Обновлённая', $detail['title']);
        $this->assertSame(5, $detail['importance']);
        $this->assertFalse($detail['isRelevant']);
        $this->assertSame([$this->tagId], $detail['tagIds']);
        $this->assertSame('editor', $detail['myRole']);
        $this->assertIso8601($detail['updatedAt'], 'note.updatedAt');

        $this->assertSame(1, $this->countRows('SELECT count(*) FROM note_tags WHERE note_id = :id', ['id' => $this->noteId]));

        $this->json($this->patchManifest(['tagIds' => []], $this->editorId));
        $this->assertSame(0, $this->countRows('SELECT count(*) FROM note_tags WHERE note_id = :id', ['id' => $this->noteId]));
    }

    public function testPatchManifestRejectsIsRelevantStringFalse(): void
    {
        // регрессия: (bool)"false" === true уводил заметку не в архив, а обратно
        $e = $this->assertApiStatus(422, fn (): Response => $this->patchManifest(['isRelevant' => 'false'], $this->ownerId));

        $this->assertSame('VALIDATION_FAILED', $e->errorCode());
        $this->assertTrue(
            $this->noteRow($this->noteId)['is_relevant'],
            'некорректный ввод не должен менять заметку',
        );
    }

    public function testPatchManifestEnforcesRoles(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->patchManifest(['title' => 'X'], $this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->patchManifest(['title' => 'X'], $this->strangerId));
        $this->assertSame('Заметка А', $this->noteRow($this->noteId)['title']);
    }

    public function testNoteLevelEditorCanEditButCannotPurge(): void
    {
        $noteId = $this->createNote($this->canvasId, $this->ownerId, 'По инвайту');
        $this->grantNoteAccess($noteId, $this->strangerId, 'editor');

        $detail = $this->json($this->patchManifest(['title' => 'Переименовано'], $this->strangerId, $noteId));
        $this->assertSame('Переименовано', $detail['title']);
        $this->assertSame('editor', $detail['myRole']);

        // матрица прав docs/api.md: editor-инвайт заметки ✖ для purge
        $this->assertApiStatus(403, fn (): Response => $this->noteController->purge($this->request(
            'DELETE',
            "/api/v1/notes/{$noteId}/purge",
            ['id' => $noteId],
            null,
            [],
            [],
            $this->strangerId,
        )));
    }

    public function testPutBodyParsesWikilinksWithoutDuplicates(): void
    {
        $targetId = $this->createNote($this->canvasId, $this->ownerId, 'Цель');
        $body = sprintf('[[Цель]] и снова [[Цель]] и [[%s]] и [[Не существует]]', $targetId);

        $detail = $this->json($this->putBody($body, $this->editorId));

        $this->assertSame(200, $this->lastStatus);
        $this->assertSame($body, $detail['bodyMd']);

        $rows = $this->rows('SELECT to_note_id, raw_target, resolved FROM indirect_links WHERE from_note_id = :id ORDER BY raw_target', ['id' => $this->noteId]);
        $this->assertCount(3, $rows, 'повторяющаяся цель не должна плодить дубли строк');
        $this->assertSame(2, count(array_filter($rows, static fn (array $r): bool => $r['to_note_id'] !== null)));
        $this->assertSame(1, count(array_filter($rows, static fn (array $r): bool => $r['to_note_id'] === null)));
    }

    public function testPutBodyRemovesStaleLinks(): void
    {
        $this->putBody('[[$this->noteId]]', $this->editorId);
        $this->assertSame(1, $this->countRows('SELECT count(*) FROM indirect_links WHERE from_note_id = :id', ['id' => $this->noteId]));

        $this->putBody('Без ссылок вовсе', $this->editorId);
        $this->assertSame(
            0,
            $this->countRows('SELECT count(*) FROM indirect_links WHERE from_note_id = :id', ['id' => $this->noteId]),
            'старые линки обязаны уйти вместе с новым телом',
        );
    }

    public function testPutBodyValidationDoesNotTouchStoredLinks(): void
    {
        $this->putBody('[[Цель]]', $this->editorId);
        $before = $this->countRows('SELECT count(*) FROM indirect_links WHERE from_note_id = :id', ['id' => $this->noteId]);

        $this->assertApiStatus(422, fn (): Response => $this->putBody(123, $this->editorId));

        $this->assertSame(
            $before,
            $this->countRows('SELECT count(*) FROM indirect_links WHERE from_note_id = :id', ['id' => $this->noteId]),
            'ошибка валидации не должна менять линки',
        );
        $this->assertSame(
            '[[Цель]]',
            $this->noteRow($this->noteId)['body_md'],
            'ошибка валидации не должна менять тело',
        );
    }

    public function testUpdatePositionValidatesAndPersists(): void
    {
        $this->assertApiStatus(422, fn (): Response => $this->patchPosition(['x' => 'abc']));
        $this->assertApiStatus(422, fn (): Response => $this->patchPosition(['y' => []]));
        $this->assertApiStatus(422, fn (): Response => $this->patchPosition(['width' => 50]));
        $this->assertApiStatus(422, fn (): Response => $this->patchPosition(['width' => 900]));
        $this->assertApiStatus(422, fn (): Response => $this->patchPosition(['width' => 300.5]));

        $response = $this->patchPosition(['x' => 42.5, 'y' => 10, 'width' => 400], $this->editorId);
        $this->assertSame(204, $response->status());
        $this->assertSame('', $response->body(), '204 обязан приходить без тела');

        $row = $this->noteRow($this->noteId);
        $this->assertSame(42.5, (float) $row['x']);
        $this->assertSame(10.0, (float) $row['y']);
        $this->assertSame(400, (int) $row['width']);
    }

    public function testUpdatePositionEnforcesCanvasRights(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->patchPosition(['x' => 1], $this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->patchPosition(['x' => 1], $this->strangerId));
    }

    public function testUpdatePositionRejectsNoteFromAnotherCanvas(): void
    {
        $otherCanvas = $this->createCanvas($this->ownerId, 'Другой');
        $foreignNote = $this->createNote($otherCanvas, $this->ownerId, 'Чужая');

        $this->assertApiStatus(404, fn (): Response => $this->patchPosition(['x' => 1], $this->ownerId, $foreignNote));
        $this->assertApiStatus(404, fn (): Response => $this->patchPosition(['x' => 1], $this->ownerId, $this->uuid()));
    }

    public function testSoftDeleteAndRestoreCycle(): void
    {
        $delete = $this->noteController->delete($this->request(
            'DELETE',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(204, $delete->status());
        $this->assertSame('', $delete->body());
        $this->assertNotNull($this->noteRow($this->noteId)['deleted_at'], 'soft delete обязан ставить deleted_at');

        $restore = $this->noteController->restore($this->request(
            'POST',
            "/api/v1/notes/{$this->noteId}/restore",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(200, $restore->status());
        $note = $this->json($restore);
        $this->assertSame($this->noteId, $note['id']);
        $this->assertNull($note['deletedAt'], 'restore обязан снимать deleted_at');
        $this->assertNull($this->noteRow($this->noteId)['deleted_at']);
    }

    public function testRestoreUnknownNoteIs404(): void
    {
        $this->assertApiStatus(404, fn (): Response => $this->restoreNote($this->uuid(), $this->ownerId));
    }

    public function testRestoreIsRestricted(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->restoreNote($this->noteId, $this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->restoreNote($this->noteId, $this->strangerId));
    }

    public function testPurgeCreatesTombstoneUnlinksEdgesAndAnswers410(): void
    {
        $targetId = $this->createNote($this->canvasId, $this->ownerId, 'Цель purge');
        $sourceId = $this->createNote($this->canvasId, $this->ownerId, 'Источник');
        $linkId = $this->insertIndirectLink($sourceId, $targetId, '[[Цель purge]]', true);

        $purge = $this->noteController->purge($this->request(
            'DELETE',
            "/api/v1/notes/{$targetId}/purge",
            ['id' => $targetId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(204, $purge->status());
        $this->assertSame('', $purge->body());
        $this->assertNull($this->noteRow($targetId), 'purge обязан удалять строку notes');

        $tombstone = $this->rows('SELECT * FROM deleted_notes WHERE note_id = :id', ['id' => $targetId]);
        $this->assertCount(1, $tombstone, 'purge обязан оставлять tombstone');
        $this->assertSame('Цель purge', $tombstone[0]['title_snapshot']);
        $this->assertSame($this->canvasId, $tombstone[0]['canvas_id']);

        $this->assertNull(
            $this->rows('SELECT to_note_id FROM indirect_links WHERE id = :id', ['id' => $linkId])[0]['to_note_id'],
            'после purge ссылка становится «ссылка удалена» (FK ON DELETE SET NULL)',
        );

        $e = $this->assertApiStatus(410, fn (): Response => $this->restoreNote($targetId, $this->ownerId));
        $this->assertSame('GONE', $e->errorCode());
    }

    public function testRestorePurgedNoteWithoutCanvasAccessIs404(): void
    {
        $targetId = $this->createNote($this->canvasId, $this->ownerId, 'Секретная');
        $this->noteController->purge($this->request(
            'DELETE',
            "/api/v1/notes/{$targetId}/purge",
            ['id' => $targetId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        // 404 раньше 410: без прав на канвас существование tombstone не раскрываем
        $this->assertApiStatus(404, fn (): Response => $this->restoreNote($targetId, $this->strangerId));
        $this->assertApiStatus(410, fn (): Response => $this->restoreNote($targetId, $this->ownerId));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createNoteAs(string $userId, array $body = []): Response
    {
        return $this->noteController->create($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/notes",
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
    private function patchManifest(array $body, string $userId, ?string $noteId = null): Response
    {
        $id = $noteId ?? $this->noteId;
        $response = $this->noteController->update($this->request(
            'PATCH',
            "/api/v1/notes/{$id}",
            ['id' => $id],
            $body,
            [],
            [],
            $userId,
        ));
        $this->lastStatus = $response->status();

        return $response;
    }

    private function putBody(mixed $bodyMd, string $userId): Response
    {
        $response = $this->noteController->updateBody($this->request(
            'PUT',
            "/api/v1/notes/{$this->noteId}/body",
            ['id' => $this->noteId],
            ['bodyMd' => $bodyMd],
            [],
            [],
            $userId,
        ));
        $this->lastStatus = $response->status();

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchPosition(array $body, ?string $userId = null, ?string $noteId = null): Response
    {
        return $this->noteController->updatePosition($this->request(
            'PATCH',
            "/api/v1/canvases/{$this->canvasId}/nodes/" . ($noteId ?? $this->noteId),
            ['id' => $this->canvasId, 'noteId' => $noteId ?? $this->noteId],
            $body,
            [],
            [],
            $userId ?? $this->ownerId,
        ));
    }

    private function restoreNote(string $noteId, string $userId): Response
    {
        return $this->noteController->restore($this->request(
            'POST',
            "/api/v1/notes/{$noteId}/restore",
            ['id' => $noteId],
            null,
            [],
            [],
            $userId,
        ));
    }

    private function insertIndirectLink(string $from, string $to, string $rawTarget, bool $resolved): string
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO indirect_links (from_note_id, to_note_id, raw_target, resolved)
             VALUES (:from, :to, :raw, :resolved) RETURNING id'
        );
        $stmt->execute(['from' => $from, 'to' => $to, 'raw' => $rawTarget, 'resolved' => $resolved]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function noteRow(string $id): ?array
    {
        $rows = $this->rows('SELECT * FROM notes WHERE id = :id', ['id' => $id]);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
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
