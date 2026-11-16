<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Services\GraphService;

require_once __DIR__ . '/Fakes/FakeNoteRepository.php';
require_once __DIR__ . '/Fakes/FakeTagRepository.php';
require_once __DIR__ . '/Fakes/FakeDirectLinkRepository.php';
require_once __DIR__ . '/Fakes/FakeIndirectLinkRepository.php';

/**
 * Unit-тесты сборки /graph (roadmap R17: GraphService вынесен из
 * CanvasController — тонкие контроллеры; фейки репозиториев без PDO, R15).
 *
 * Контракт (docs/api.md): узлы/рёбра без доступа к концу исключаются,
 * indirect-ссылки получают label «ссылка удалена», кросс-канвасные цели
 * не раскрываются вовсе.
 */
final class GraphServiceTest extends TestCase
{
    private const CANVAS = 'canvas-1';
    private const OTHER_CANVAS = 'canvas-2';

    private FakeNoteRepository $notes;
    private FakeTagRepository $tags;
    private FakeDirectLinkRepository $directLinks;
    private FakeIndirectLinkRepository $indirectLinks;
    private GraphService $service;

    protected function setUp(): void
    {
        $this->notes = new FakeNoteRepository();
        $this->tags = new FakeTagRepository();
        $this->directLinks = new FakeDirectLinkRepository();
        $this->indirectLinks = new FakeIndirectLinkRepository();
        $this->service = new GraphService($this->notes, $this->tags, $this->directLinks, $this->indirectLinks);

        foreach (['note-1', 'note-2'] as $id) {
            $this->notes->add([
                'id' => $id,
                'canvas_id' => self::CANVAS,
                'author_id' => 'user-1',
                'title' => 'Заметка ' . $id,
                'importance' => 4,
                'is_relevant' => true,
                'x' => 10.5,
                'y' => 20.0,
                'width' => 240,
                'deleted_at' => null,
            ]);
        }
        $this->notes->add([
            'id' => 'note-foreign',
            'canvas_id' => self::OTHER_CANVAS,
            'author_id' => 'user-2',
            'title' => 'Чужая',
            'importance' => 1,
            'is_relevant' => true,
            'x' => 0.0,
            'y' => 0.0,
            'width' => 200,
            'deleted_at' => null,
        ]);
    }

    public function testBuildsNodesWithCastedFields(): void
    {
        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertCount(2, $graph['nodes']);
        $node = $graph['nodes'][0];
        $this->assertSame('note-1', $node['id']);
        $this->assertSame('Заметка note-1', $node['title']);
        $this->assertSame(4, $node['importance']);
        $this->assertTrue($node['isRelevant']);
        $this->assertSame(10.5, $node['x']);
        $this->assertSame(240, $node['width']);
        $this->assertNull($node['deletedAt']);
        $this->assertSame([], $node['tags']);
    }

    public function testAttachesTagNamesToNodes(): void
    {
        $this->tags->setNoteTagNames('note-1', ['важное', 'idea']);

        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertSame(['важное', 'idea'], $graph['nodes'][0]['tags']);
        $this->assertSame([], $graph['nodes'][1]['tags']);
    }

    public function testMapsCanvasTags(): void
    {
        $this->tags->add(['id' => 'tag-1', 'canvas_id' => self::CANVAS, 'name' => 'idea', 'color' => '#ff0000']);

        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertSame(
            [['id' => 'tag-1', 'name' => 'idea', 'color' => '#ff0000']],
            $graph['tags'],
        );
    }

    public function testDirectEdgeKeepsOnlyVisibleTargets(): void
    {
        $this->directLinks->add([
            'id' => 'edge-ok',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-2',
            'target_canvas_id' => self::CANVAS,
            'target_deleted_at' => null,
        ]);
        // цель в другом канвасе — ребро исключается целиком
        $this->directLinks->add([
            'id' => 'edge-drop',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-foreign',
            'target_canvas_id' => self::OTHER_CANVAS,
            'target_deleted_at' => null,
        ]);

        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertSame(
            [[
                'id' => 'edge-ok',
                'type' => 'direct',
                'from' => 'note-1',
                'to' => 'note-2',
                'state' => 'ok',
                'label' => null,
            ]],
            $graph['edges'],
        );
    }

    public function testIndirectEdgeResolvedToVisibleTargetIsOk(): void
    {
        $this->indirectLinks->add([
            'id' => 'il-1',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-2',
            'raw_target' => '[[note-2]]',
            'resolved' => true,
            'target_canvas_id' => self::CANVAS,
            'target_deleted_at' => null,
        ]);

        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertSame('ok', $graph['edges'][0]['state']);
        $this->assertSame('note-2', $graph['edges'][0]['to']);
        $this->assertNull($graph['edges'][0]['label']);
    }

    public function testIndirectEdgeToPurgedTargetBecomesRemovedWithLabel(): void
    {
        $this->indirectLinks->add([
            'id' => 'il-2',
            'from_note_id' => 'note-1',
            'to_note_id' => null,
            'raw_target' => '[[потерянная]]',
            'resolved' => false,
            'deleted_target_id' => 'purged-note',
            'target_canvas_id' => null,
            'target_deleted_at' => null,
        ]);

        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertSame('removed', $graph['edges'][0]['state']);
        $this->assertNull($graph['edges'][0]['to']);
        $this->assertSame('[[потерянная]]', $graph['edges'][0]['label']);
    }

    public function testUnresolvedTypoLinkProducesNoEdgeAtAll(): void
    {
        // Опечатка/переименование: цель не найдена и не стиралась
        // (deleted_target_id IS NULL) — ребра нет (data-model.md:147).
        $this->indirectLinks->add([
            'id' => 'il-typo',
            'from_note_id' => 'note-1',
            'to_note_id' => null,
            'raw_target' => '[[Опечатка]]',
            'resolved' => false,
            'deleted_target_id' => null,
            'target_canvas_id' => null,
            'target_deleted_at' => null,
        ]);

        foreach (['owner', 'editor', 'viewer', 'none'] as $role) {
            $graph = $this->service->build(self::CANVAS, 'user-1', $role);

            $this->assertSame([], $graph['edges'], 'роль ' . $role . ' не должна получать ребро на опечатку');
        }
    }

    public function testIndirectEdgeAcrossCanvasesIsDroppedEntirely(): void
    {
        $this->indirectLinks->add([
            'id' => 'il-3',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-foreign',
            'raw_target' => '[[чужая]]',
            'resolved' => true,
            'target_canvas_id' => self::OTHER_CANVAS,
            'target_deleted_at' => null,
        ]);

        $graph = $this->service->build(self::CANVAS, 'user-1', 'owner');

        $this->assertSame([], $graph['edges']);
    }

    public function testEmptyCanvasProducesEmptyPayload(): void
    {
        $graph = $this->service->build('no-such-canvas', null, 'viewer');

        $this->assertSame(['nodes' => [], 'edges' => [], 'tags' => []], $graph);
    }

    // --- R12: состояния removed ---

    public function testIndirectEdgeToSoftDeletedTargetKeepsTargetIdForEditor(): void
    {
        $this->notes->softDelete('note-2');
        $this->indirectLinks->add([
            'id' => 'il-soft',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-2',
            'raw_target' => '[[note-2]]',
            'resolved' => true,
            'target_canvas_id' => self::CANVAS,
            'target_deleted_at' => '2026-11-16 00:00:00+00',
        ]);

        foreach (['owner', 'editor'] as $role) {
            $graph = $this->service->build(self::CANVAS, 'user-1', $role);

            $this->assertCount(1, $graph['edges'], 'role=' . $role);
            $this->assertSame('removed', $graph['edges'][0]['state']);
            $this->assertSame('note-2', $graph['edges'][0]['to'], 'to = id soft-deleted цели');
            $this->assertSame('[[note-2]]', $graph['edges'][0]['label']);
        }
    }

    public function testIndirectEdgeToSoftDeletedTargetIsDroppedBelowEditor(): void
    {
        $this->notes->softDelete('note-2');
        $this->indirectLinks->add([
            'id' => 'il-soft',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-2',
            'raw_target' => '[[note-2]]',
            'resolved' => true,
            'target_canvas_id' => self::CANVAS,
            'target_deleted_at' => '2026-11-16 00:00:00+00',
        ]);

        foreach (['viewer', 'none'] as $role) {
            $graph = $this->service->build(self::CANVAS, 'user-1', $role);

            $this->assertSame([], $graph['edges'], 'soft-deleted маркер не показывается роли ' . $role);
        }
    }

    public function testDirectEdgeToSoftDeletedTargetIsRemovedOnlyForEditor(): void
    {
        $this->notes->softDelete('note-2');
        $this->directLinks->add([
            'id' => 'edge-soft',
            'from_note_id' => 'note-1',
            'to_note_id' => 'note-2',
            'target_canvas_id' => self::CANVAS,
            'target_deleted_at' => '2026-11-16 00:00:00+00',
        ]);

        $editor = $this->service->build(self::CANVAS, 'user-1', 'editor');
        $this->assertCount(1, $editor['edges']);
        $this->assertSame('removed', $editor['edges'][0]['state']);
        $this->assertSame('note-2', $editor['edges'][0]['to']);
        $this->assertNull($editor['edges'][0]['label'], 'у прямой ссылки нет исходного текста');

        $viewer = $this->service->build(self::CANVAS, 'user-1', 'viewer');
        $this->assertSame([], $viewer['edges']);
    }

    public function testRemovedMarkersAreHiddenFromAnonymousButShownToAnyAuthorizedRole(): void
    {
        $purged = [
            'id' => 'il-purged',
            'from_note_id' => 'note-1',
            'to_note_id' => null,
            'raw_target' => '[[потерянная]]',
            'resolved' => false,
            'deleted_target_id' => 'purged-note',
            'target_canvas_id' => null,
            'target_deleted_at' => null,
        ];
        $this->indirectLinks->add($purged);

        $anonymous = $this->service->build(self::CANVAS, null, 'viewer');
        $this->assertSame([], $anonymous['edges'], 'анониму пометки «ссылка удалена» не показываются');

        // purged-пометка положена любому авторизованному, включая роль none
        $none = $this->service->build(self::CANVAS, 'user-1', 'none');
        $this->assertCount(1, $none['edges']);
        $this->assertSame('removed', $none['edges'][0]['state']);
        $this->assertNull($none['edges'][0]['to']);
        $this->assertSame('[[потерянная]]', $none['edges'][0]['label']);
    }
}
