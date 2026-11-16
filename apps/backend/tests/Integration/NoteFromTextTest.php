<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Request;
use Vistynq\Http\Response;

/**
 * POST /canvases/{id}/notes/from-text — импорт .md/.txt
 * (docs/api.md §Заметки, docs/openapi.yaml, ROADMAP R13).
 *
 * multipart: file (.md/.txt ≤1 МБ), поля title?/x?/y?; тело файла →
 * bodyMd + парсинг [[wikilinks]], заголовок — из поля, иначе из имени файла.
 */
final class NoteFromTextTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $editorId;
    private string $viewerId;
    private string $strangerId;
    private string $unverifiedId;
    private string $canvasId;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->strangerId = $this->createUser();
        $this->unverifiedId = $this->createUser(false);

        $this->canvasId = $this->createCanvas($this->ownerId, 'Импорт файлов');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testImportWithTitleFieldReturnsCreatedNote(): void
    {
        $response = $this->fromText(
            ['title' => 'Импортированный документ', 'x' => 40, 'y' => 60],
            $this->upload('draft.md', "# Заголовок\n\nТекст заметки"),
            $this->ownerId,
        );

        $this->assertSame(201, $response->status());
        $note = $this->json($response);
        $this->assertSame('Импортированный документ', $note['title'], 'title берётся из поля, а не из имени файла');
        $this->assertSame($this->canvasId, $note['canvasId']);
        $this->assertIso8601($note['createdAt'], 'createdAt');

        $row = $this->noteRow((string) $note['id']);
        $this->assertSame("# Заголовок\n\nТекст заметки", $row['body_md'], 'тело файла становится bodyMd');
        $this->assertSame(40.0, (float) $row['x'], 'x из поля');
        $this->assertSame(60.0, (float) $row['y'], 'y из поля');
    }

    public function testTitleFallsBackToFileNameWithoutExtension(): void
    {
        $response = $this->fromText([], $this->upload('План наезд.md', 'текст'), $this->ownerId);

        $this->assertSame(201, $response->status());
        $this->assertSame('План наезд', $this->json($response)['title'], 'без поля title — имя файла без расширения');
    }

    public function testTxtFileIsAcceptedToo(): void
    {
        $response = $this->fromText([], $this->upload('заметка.txt', 'просто текст'), $this->editorId);

        $this->assertSame(201, $response->status());
        $this->assertSame('заметка', $this->json($response)['title']);
    }

    public function testWikilinksInImportedBodyAreParsed(): void
    {
        $targetId = $this->createNote($this->canvasId, $this->ownerId, 'Существующая цель');

        $response = $this->fromText(
            ['title' => 'Ссылочная'],
            $this->upload('links.md', 'Смотри [[Существующая цель]] и [[Опечатка]]'),
            $this->ownerId,
        );

        $this->assertSame(201, $response->status());
        $noteId = (string) $this->json($response)['id'];

        $links = $this->rows(
            'SELECT to_note_id, raw_target, resolved FROM indirect_links WHERE from_note_id = :id ORDER BY raw_target',
            ['id' => $noteId],
        );
        $this->assertCount(2, $links, 'wikilinks из файла обязаны стать строками indirect_links');

        $resolved = array_values(array_filter($links, static fn (array $l): bool => $l['to_note_id'] === $targetId));
        $this->assertCount(1, $resolved, 'ссылка на существующую заметку резолвится');
        $this->assertTrue($this->pdoBool($resolved[0]['resolved']));

        $unresolved = array_values(array_filter($links, static fn (array $l): bool => $l['to_note_id'] === null));
        $this->assertCount(1, $unresolved, 'опечатка сохраняется неразрешённой');
        $this->assertFalse($this->pdoBool($unresolved[0]['resolved']));
    }

    public function testMissingFileIs422(): void
    {
        $e = $this->assertApiStatus(422, fn (): Response => $this->fromText(['title' => 'X'], null, $this->ownerId));

        $this->assertArrayHasKey('file', $e->errorDetails() ?? [], '422 указывает на поле file');
    }

    public function testUnsupportedExtensionIs415(): void
    {
        $e = $this->assertApiStatus(
            415,
            fn (): Response => $this->fromText([], $this->upload('image.png', 'binary'), $this->ownerId),
        );

        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $e->errorCode());
    }

    public function testOversizedFileIs413(): void
    {
        $file = $this->upload('big.md', str_repeat('a', 1024));
        $file['size'] = 2 * 1024 * 1024; // > 1 МБ по заявленному размеру

        $e = $this->assertApiStatus(
            413,
            fn (): Response => $this->fromText([], $file, $this->ownerId),
        );

        $this->assertSame('PAYLOAD_TOO_LARGE', $e->errorCode());
    }

    public function testInvalidTitleFieldIs422(): void
    {
        $this->assertApiStatus(
            422,
            fn (): Response => $this->fromText(
                ['title' => str_repeat('ы', 201)],
                $this->upload('a.md', 'текст'),
                $this->ownerId,
            ),
        );
    }

    public function testRightsMatrix(): void
    {
        $file = $this->upload('a.md', 'текст');

        // viewer видит канвас, но не редактирует → 403; посторонний → 404
        $this->assertApiStatus(403, fn (): Response => $this->fromText([], $file, $this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->fromText([], $file, $this->strangerId));

        // мутации требуют верифицированного email (docs/api.md)
        $this->assertApiStatus(403, fn (): Response => $this->fromText([], $file, $this->unverifiedId));

        // editor импортирует — 201
        $this->assertSame(201, $this->fromText([], $file, $this->editorId)->status());
    }

    public function testFailedImportCreatesNoNote(): void
    {
        $before = $this->countRows(
            'SELECT count(*) FROM notes WHERE canvas_id = :c',
            ['c' => $this->canvasId],
        );

        $this->assertApiStatus(415, fn (): Response => $this->fromText(
            ['title' => 'Не создастся'],
            $this->upload('bad.exe', 'x'),
            $this->ownerId,
        ));

        $this->assertSame(
            $before,
            $this->countRows('SELECT count(*) FROM notes WHERE canvas_id = :c', ['c' => $this->canvasId]),
            '415 не должен оставлять заметку',
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $file
     */
    private function fromText(array $fields, ?array $file, string $userId): Response
    {
        $request = new Request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/notes/from-text",
            [],
            ['content-type' => 'multipart/form-data; boundary=x'],
            '',
            [],
            ['id' => $this->canvasId],
            [
                'authUserId' => $userId,
                'multipart' => [
                    'fields' => $fields,
                    'files' => $file === null ? [] : ['file' => $file],
                ],
            ],
        );

        return $this->noteController->fromText($request);
    }

    /**
     * @return array<string, mixed> $_FILES-подобная запись с реальным tmp-файлом
     */
    private function upload(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'vistynq-import-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return [
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noteRow(string $id): array
    {
        $rows = $this->rows('SELECT * FROM notes WHERE id = :id', ['id' => $id]);
        $this->assertNotEmpty($rows, 'заметка должна существовать: ' . $id);

        return $rows[0];
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
        return (int) $this->rows($sql, $params)[0]['count'];
    }

    private function pdoBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [true, 't', 'true', '1', 1], true);
    }
}
