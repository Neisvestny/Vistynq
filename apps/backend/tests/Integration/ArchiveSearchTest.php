<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\NoteService;

/**
 * Архив канваса и поиск (docs/ROADMAP.md R12, docs/api.md §Канвасы).
 * Архив: «неактуально» = живые is_relevant=false; «удалённые» = soft-deleted
 * плюс tombstone'ы purge. Поиск: регистронезависимый, по заголовкам и тегам,
 * без удалённых, с литеральным вводом %/_.
 */
final class ArchiveSearchTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $viewerId;
    private string $editorId;
    private string $strangerId;
    private string $canvasId;
    private string $notRelevantId;
    private string $softDeletedId;
    private string $purgedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Архив и поиск');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');

        $pdo = $this->db->pdo();

        $this->notRelevantId = $this->createNote($this->canvasId, $this->ownerId, 'Рутина');
        $pdo->prepare('UPDATE notes SET is_relevant = false WHERE id = :id')
            ->execute(['id' => $this->notRelevantId]);

        $this->createNote($this->canvasId, $this->ownerId, 'Антикризисный план');
        $tagged = $this->createNote($this->canvasId, $this->ownerId, 'Проект X');
        $tagId = $this->createTag($this->canvasId, 'машинное обучение');
        $pdo->prepare('INSERT INTO note_tags (note_id, tag_id) VALUES (:n, :t)')
            ->execute(['n' => $tagged, 't' => $tagId]);

        $this->createNote($this->canvasId, $this->ownerId, '100% рост');
        $this->createNote($this->canvasId, $this->ownerId, 'A_B');
        $this->createNote($this->canvasId, $this->ownerId, 'AXB');

        $this->softDeletedId = $this->createNote($this->canvasId, $this->ownerId, 'Удаляемая');
        $this->purgedId = $this->createNote($this->canvasId, $this->ownerId, 'Стираемая');

        // Порядок в «удалённых» должен быть по deleted_at DESC — фиксируем
        // разницу явно, чтобы результат не зависел от точности now().
        $pdo->prepare('UPDATE notes SET deleted_at = now() - interval \'1 hour\' WHERE id = :id')
            ->execute(['id' => $this->softDeletedId]);

        $this->service(NoteService::class)->purge($this->purgedId, $this->ownerId);
    }

    public function testArchiveSplitsNotRelevantAndDeleted(): void
    {
        $data = $this->archiveAs($this->ownerId);

        $notRelevantIds = array_column($data['notRelevant'], 'id');
        $this->assertSame([$this->notRelevantId], $notRelevantIds, 'в «неактуально» — только живые is_relevant=false');

        $deletedIds = array_column($data['deleted'], 'id');
        $this->assertCount(2, $deletedIds, 'в «удалённые» — soft-deleted и tombstone');
        $this->assertSame([$this->purgedId, $this->softDeletedId], $deletedIds, 'сортировка по deleted_at DESC');

        // tombstone пережил purge: частичная сущность, но обязательные поля есть
        $tombstone = $data['deleted'][0];
        $this->assertSame('Стираемая', $tombstone['title']);
        $this->assertSame($this->canvasId, $tombstone['canvasId']);
        $this->assertIso8601($tombstone['deletedAt'], 'tombstone.deletedAt');

        // soft-deleted заметка остаётся полноценной сущностью Note
        $softDeleted = $data['deleted'][1];
        $this->assertSame('Удаляемая', $softDeleted['title']);
        $this->assertIso8601($softDeleted['deletedAt'], 'softDeleted.deletedAt');
        $this->assertArrayHasKey('importance', $softDeleted);
        $this->assertArrayHasKey('tagIds', $softDeleted);
    }

    public function testArchiveExcludesLiveRelevantNotes(): void
    {
        $data = $this->archiveAs($this->ownerId);
        $ids = array_merge(array_column($data['notRelevant'], 'id'), array_column($data['deleted'], 'id'));

        $liveRelevant = $this->rows(
            'SELECT id FROM notes WHERE canvas_id = :c AND deleted_at IS NULL AND is_relevant = true',
            ['c' => $this->canvasId],
        );
        foreach ($liveRelevant as $row) {
            $this->assertNotContains($row['id'], $ids, 'актуальная заметка не должна попадать в архив');
        }
    }

    public function testArchiveRequiresEditor(): void
    {
        $this->assertApiStatus(403, fn (): Response => $this->archiveAs($this->viewerId));
        $this->assertApiStatus(404, fn (): Response => $this->archiveAs($this->strangerId));
    }

    public function testSearchMatchesTitleCaseInsensitively(): void
    {
        $found = $this->searchAs('антикризис', $this->ownerId);
        $this->assertCount(1, $found);
        $this->assertSame('Антикризисный план', $found[0]['title']);

        $upper = $this->searchAs('АНТИКРИЗИС', $this->ownerId);
        $this->assertCount(1, $upper, 'регистр не важен');
    }

    public function testSearchMatchesTagName(): void
    {
        $found = $this->searchAs('машинное', $this->ownerId);

        $this->assertCount(1, $found);
        $this->assertSame('Проект X', $found[0]['title']);
        $this->assertSame($this->canvasId, $found[0]['canvasId']);
        $this->assertArrayHasKey('tagIds', $found[0], 'результат поиска — полная сущность Note');
        $this->assertArrayHasKey('importance', $found[0]);
    }

    public function testSearchExcludesDeletedAndPurged(): void
    {
        $this->assertSame([], $this->searchAs('Удаляемая', $this->ownerId), 'soft-deleted не ищутся');
        $this->assertSame([], $this->searchAs('Стираемая', $this->ownerId), 'purged не ищутся');
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $percent = $this->searchAs('100%', $this->ownerId);
        $this->assertCount(1, $percent, '% должен искаться буквально');
        $this->assertSame('100% рост', $percent[0]['title']);

        $underscore = $this->searchAs('a_b', $this->ownerId);
        $this->assertCount(1, $underscore, '_ должен искаться буквально');
        $this->assertSame('A_B', $underscore[0]['title'], '_ не должен работать как одиночный символ');
    }

    public function testSearchWithEmptyQueryReturnsNothing(): void
    {
        $this->assertSame([], $this->searchAs('', $this->ownerId));
        $this->assertSame([], $this->searchAs('   ', $this->ownerId));
    }

    public function testSearchRequiresCanvasAccess(): void
    {
        $this->assertApiStatus(404, fn (): array => $this->searchAs('план', $this->strangerId));
        $this->assertApiStatus(404, fn (): array => $this->searchAs('план', null));
    }

    public function testSearchOnPublicLinkCanvasWorksAnonymously(): void
    {
        $this->setCanvasAccessLevel($this->canvasId, 'public');

        $found = $this->searchAs('план', null);

        $this->assertCount(1, $found);
        $this->assertSame('Антикризисный план', $found[0]['title']);
    }

    public function testPrivateNoteHiddenFromCanvasRightsInArchiveAndSearch(): void
    {
        $privateId = $this->createNote($this->canvasId, $this->ownerId, 'Секретный отчёт');
        $this->db->pdo()
            ->prepare("UPDATE notes SET is_relevant = false, visibility = 'private' WHERE id = :id")
            ->execute(['id' => $privateId]);

        // Автор (owner) видит приватную заметку и в архиве, и в поиске
        $this->assertContains(
            $privateId,
            array_column($this->archiveAs($this->ownerId)['notRelevant'], 'id'),
        );
        $this->assertNotSame([], $this->searchAs('секретный', $this->ownerId));

        // Editor канваса без note_access — не видит нигде (docs/api.md)
        $this->assertNotContains(
            $privateId,
            array_column($this->archiveAs($this->editorId)['notRelevant'], 'id'),
        );
        $this->assertSame([], $this->searchAs('секретный', $this->editorId));

        // Выданный note_access открывает и архив, и поиск
        $this->grantNoteAccess($privateId, $this->editorId, 'viewer');
        $this->assertContains(
            $privateId,
            array_column($this->archiveAs($this->editorId)['notRelevant'], 'id'),
        );
        $this->assertNotSame([], $this->searchAs('секретный', $this->editorId));

        // Аноним на публичном канвасе — только public
        $this->setCanvasAccessLevel($this->canvasId, 'public');
        $this->assertSame([], $this->searchAs('секретный', null));
    }

    public function testInvalidBearerOnSearchIs401(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $request = $this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/search",
            ['id' => $this->canvasId],
            null,
            ['q' => 'план'],
            ['authorization' => 'Bearer expired-or-broken'],
        );

        $this->assertApiStatus(401, fn (): Response => $middleware->process(
            $request,
            static fn (Request $r): Response => Response::json(['reached' => true]),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function archiveAs(string $userId): array
    {
        $response = $this->noteController->archive($this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/archive",
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
     * @return list<array<string, mixed>>
     */
    private function searchAs(string $q, ?string $userId): array
    {
        $response = $this->noteController->search($this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/search",
            ['id' => $this->canvasId],
            null,
            ['q' => $q],
            [],
            $userId,
        ));

        $this->assertSame(200, $response->status());

        return $this->json($response)['items'];
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
}
