<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Controllers\AccessController;
use Vistynq\Controllers\SharedController;

/**
 * Публичные read-only страницы (docs/api.md §Публичный доступ, R16):
 * аноним видит только public-заметки public-канваса, без access-деталей,
 * без removed-рёбер и без приватных wikilinks/тегов.
 */
final class SharedAccessTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $canvasId;
    private string $notePublicId;
    private string $noteSecondId;
    private string $notePrivateId;
    private string $noteDeletedId;
    private string $foreignNoteId;
    private string $tagPublicId;
    private string $tagPrivateId;
    private string $slug;
    private AccessController $access;
    private SharedController $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = $this->service(AccessController::class);
        $this->shared = $this->service(SharedController::class);

        $this->ownerId = $this->createUser();
        $this->canvasId = $this->createCanvas($this->ownerId, 'Публичный канвас');

        $this->notePublicId = $this->createNote($this->canvasId, $this->ownerId, 'Открытая');
        $this->noteSecondId = $this->createNote($this->canvasId, $this->ownerId, 'Вторая');
        $this->notePrivateId = $this->createNote($this->canvasId, $this->ownerId, 'Секретная');
        $this->noteDeletedId = $this->createNote($this->canvasId, $this->ownerId, 'Удалённая');

        $otherOwnerId = $this->createUser();
        $otherCanvasId = $this->createCanvas($otherOwnerId, 'Чужой канвас');
        $this->foreignNoteId = $this->createNote($otherCanvasId, $otherOwnerId, 'Чужая');

        $this->db->pdo()->prepare("UPDATE notes SET visibility = 'private' WHERE id = :id")
            ->execute(['id' => $this->notePrivateId]);
        $this->db->pdo()->prepare('UPDATE notes SET deleted_at = now() WHERE id = :id')
            ->execute(['id' => $this->noteDeletedId]);
        $this->db->pdo()->prepare(
            'UPDATE notes SET body_md = :body WHERE id = :id'
        )->execute([
            'id' => $this->notePublicId,
            'body' => "# Открытая\n\n[[Секретная]] [[Вторая]] [[Нет такой]]"
                . ' <script>alert(1)</script> <img src=x onerror=alert(1)>',
        ]);

        $this->tagPublicId = $this->createTag($this->canvasId, 'публичный-тег');
        $this->tagPrivateId = $this->createTag($this->canvasId, 'секретный-тег');
        $this->attachTag($this->notePublicId, $this->tagPublicId);
        $this->attachTag($this->notePrivateId, $this->tagPrivateId);

        $this->insertDirectLink($this->notePublicId, $this->noteSecondId);
        $this->insertDirectLink($this->notePublicId, $this->notePrivateId);
        $this->insertIndirectLink($this->notePublicId, $this->notePrivateId, '[[Секретная]]', true);

        $this->setCanvasAccessLevel($this->canvasId, 'public');
        $this->slug = $this->canvasSlug();
    }

    public function testGraphIsAnonymousAndFiltersPrivateContent(): void
    {
        $response = $this->shared->canvas($this->request(
            'GET',
            '/api/v1/shared/canvases/' . $this->slug,
            ['slug' => $this->slug],
        ));
        $this->assertSame(200, $response->status());

        $data = $this->json($response);
        $this->assertArrayNotHasKey('access', $data['canvas'], 'публичный канвас не раскрывает access-детали');
        $this->assertSame($this->canvasId, $data['canvas']['id']);

        $nodeIds = array_column($data['nodes'], 'id');
        sort($nodeIds);
        $expected = [$this->notePublicId, $this->noteSecondId];
        sort($expected);
        $this->assertSame($expected, $nodeIds, 'приватные/удалённые/чужие заметки не попадают в публичный граф');

        $this->assertSame([$this->tagPublicId], array_column($data['tags'], 'id'), 'теги приватных заметок скрыты');

        $direct = array_values(array_filter($data['edges'], static fn (array $e): bool => $e['type'] === 'direct'));
        $this->assertCount(1, $direct, 'ребро на приватную заметку исключено, removed-рёбра анониму не показываются');
        $this->assertSame($this->noteSecondId, $direct[0]['to']);
    }

    public function testUnknownPrivateAndMalformedSlugsAre404(): void
    {
        $this->assertApiStatus(404, fn (): mixed => $this->shared->canvas($this->request(
            'GET',
            '/api/v1/shared/canvases/deadbeefdeadbeef',
            ['slug' => 'deadbeefdeadbeef'],
        )));

        $this->assertApiStatus(404, fn (): mixed => $this->shared->canvas($this->request(
            'GET',
            '/api/v1/shared/canvases/BAD_SLUG',
            ['slug' => 'BAD_SLUG'],
        )));

        $this->setCanvasAccessLevel($this->canvasId, 'private');
        $this->assertApiStatus(404, fn (): mixed => $this->shared->canvas($this->request(
            'GET',
            '/api/v1/shared/canvases/' . $this->slug,
            ['slug' => $this->slug],
        )));
    }

    public function testRepeatedPublicRotatesSlugAndInvalidatesOldLink(): void
    {
        $oldSlug = $this->slug;

        $newSlug = $this->json($this->access->setCanvasLevel($this->request(
            'PUT',
            "/api/v1/canvases/{$this->canvasId}/access-level",
            ['id' => $this->canvasId],
            ['level' => 'public'],
            [],
            [],
            $this->ownerId,
        )))['publicSlug'];

        $this->assertNotSame($oldSlug, $newSlug);

        $this->assertApiStatus(404, fn (): mixed => $this->shared->canvas($this->request(
            'GET',
            '/api/v1/shared/canvases/' . $oldSlug,
            ['slug' => $oldSlug],
        )));

        $response = $this->shared->canvas($this->request(
            'GET',
            '/api/v1/shared/canvases/' . $newSlug,
            ['slug' => $newSlug],
        ));
        $this->assertSame(200, $response->status());
    }

    public function testNoteRendersMarkdownAndOnlyPublicWikilinks(): void
    {
        $response = $this->shared->note($this->request(
            'GET',
            "/api/v1/shared/canvases/{$this->slug}/notes/{$this->notePublicId}",
            ['slug' => $this->slug, 'noteId' => $this->notePublicId],
        ));
        $this->assertSame(200, $response->status());

        $data = $this->json($response);
        $this->assertSame($this->notePublicId, $data['note']['id']);
        $this->assertSame('public', $data['note']['visibility']);
        $this->assertSame('viewer', $data['note']['myRole']);

        $html = $data['bodyHtml'];
        $this->assertStringContainsString('<h1>Открытая</h1>', $html);
        $this->assertStringContainsString(
            '/shared/canvas/' . $this->slug . '/note/' . $this->noteSecondId,
            $html,
            'публичная wikilink-цель становится ссылкой',
        );
        $this->assertStringNotContainsString('/note/' . $this->notePrivateId, $html, 'приватная цель не раскрывается');
        $this->assertStringContainsString('Секретная', $html, 'недоступная цель остаётся plain text');
        $this->assertStringContainsString('Нет такой', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img', $html, 'raw HTML-теги не проходят в bodyHtml');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testPrivateDeletedForeignAndUnknownNotesAre404(): void
    {
        foreach ([$this->notePrivateId, $this->noteDeletedId, $this->foreignNoteId, $this->uuid()] as $noteId) {
            $this->assertApiStatus(404, fn (): mixed => $this->shared->note($this->request(
                'GET',
                "/api/v1/shared/canvases/{$this->slug}/notes/{$noteId}",
                ['slug' => $this->slug, 'noteId' => $noteId],
            )), 'note ' . $noteId);
        }
    }

    private function canvasSlug(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT public_slug FROM canvases WHERE id = :id');
        $stmt->execute(['id' => $this->canvasId]);
        $slug = $stmt->fetchColumn();
        $this->assertIsString($slug);

        return $slug;
    }

    private function attachTag(string $noteId, string $tagId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO note_tags (note_id, tag_id) VALUES (:note_id, :tag_id) ON CONFLICT DO NOTHING'
        )->execute(['note_id' => $noteId, 'tag_id' => $tagId]);
    }

    private function insertDirectLink(string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO direct_links (from_note_id, to_note_id, created_by) VALUES (:from, :to, :by)'
        )->execute(['from' => $from, 'to' => $to, 'by' => $this->ownerId]);
    }

    private function insertIndirectLink(string $from, ?string $to, string $rawTarget, bool $resolved): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO indirect_links (from_note_id, to_note_id, raw_target, resolved) VALUES (:from, :to, :raw, :resolved)'
        )->execute(['from' => $from, 'to' => $to, 'raw' => $rawTarget, 'resolved' => $resolved]);
    }
}
