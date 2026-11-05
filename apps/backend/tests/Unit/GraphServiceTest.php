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
        $graph = $this->service->build(self::CANVAS, 'user-1');

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

        $graph = $this->service->build(self::CANVAS, 'user-1');

        $this->assertSame(['важное', 'idea'], $graph['nodes'][0]['tags']);
        $this->assertSame([], $graph['nodes'][1]['tags']);
    }

    public function testMapsCanvasTags(): void
    {
        $this->tags->add(['id' => 'tag-1', 'canvas_id' => self::CANVAS, 'name' => 'idea', 'color' => '#ff0000']);

        $graph = $this->service->build(self::CANVAS, 'user-1');

        $this->assertSame(
            [['id' => 'tag-1', 'name' => 'idea', 'color' => '#ff0000']],
            $graph['tags'],
        );
    }

    public function testDirectEdgeKeepsOnlyVisibleTargets(): void
    {
        $this->directLinks->add(['id' => 'edge-ok', 'from_note_id' => 'note-1', 'to_note_id' => 'note-2']);
        // цель не в видимом множестве (другой канвас) — ребро исключается
        $this->directLinks->add(['id' => 'edge-drop', 'from_note_id' => 'note-1', 'to_note_id' => 'note-foreign']);

        $graph = $this->service->build(self::CANVAS, 'user-1');

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

        $graph = $this->service->build(self::CANVAS, 'user-1');

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
            'target_canvas_id' => null,
            'target_deleted_at' => null,
        ]);

        $graph = $this->service->build(self::CANVAS, 'user-1');

        $this->assertSame('removed', $graph['edges'][0]['state']);
        $this->assertNull($graph['edges'][0]['to']);
        $this->assertSame('[[потерянная]]', $graph['edges'][0]['label']);
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

        $graph = $this->service->build(self::CANVAS, 'user-1');

        $this->assertSame([], $graph['edges']);
    }

    public function testEmptyCanvasProducesEmptyPayload(): void
    {
        $graph = $this->service->build('no-such-canvas', null);

        $this->assertSame(['nodes' => [], 'edges' => [], 'tags' => []], $graph);
    }
}
