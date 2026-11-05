<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\DirectLinkRepositoryInterface;
use Vistynq\Repositories\IndirectLinkRepositoryInterface;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Repositories\TagRepositoryInterface;

/**
 * Сборка payload'а GET /canvases/{id}/graph: nodes, edges, tags
 * (docs/api.md, docs/architecture.md §4).
 *
 * Вынесена из CanvasController (roadmap R17 — тонкие контроллеры):
 * здесь только свёртка выборок репозиториев в публикуемую форму, без
 * Request/Response и без доступа к PDO.
 */
final class GraphService
{
    public function __construct(
        private readonly NoteRepositoryInterface $notes,
        private readonly TagRepositoryInterface $tags,
        private readonly DirectLinkRepositoryInterface $directLinks,
        private readonly IndirectLinkRepositoryInterface $indirectLinks,
    ) {
    }

    /**
     * @param string      $canvasId канвас (доступ уже проверен вызывающим кодом
     *                             через AccessService::getCanvasContext)
     * @param string|null $userId   null — анонимный вызов публичного канваса
     *
     * @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>, tags: list<array<string,mixed>>}
     */
    public function build(string $canvasId, ?string $userId): array
    {
        $noteRows = $this->notes->findGraphNotes($canvasId, $userId);

        /** @var array<string, true> $visible */
        $visible = [];
        $nodes = [];
        foreach ($noteRows as $row) {
            $id = (string) $row['id'];
            $visible[$id] = true;
            $nodes[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'importance' => (int) $row['importance'],
                'isRelevant' => (bool) $row['is_relevant'],
                'x' => (float) $row['x'],
                'y' => (float) $row['y'],
                'width' => (int) $row['width'],
                'deletedAt' => null,
                'tags' => [],
                'authorId' => (string) $row['author_id'],
            ];
        }

        $noteIds = array_keys($visible);
        $tagNamesByNote = $this->tags->findNoteTagNames($canvasId);
        foreach ($nodes as $index => $node) {
            $nodes[$index]['tags'] = $tagNamesByNote[$node['id']] ?? [];
        }

        $edges = [];
        foreach ($this->directLinks->findEdgesBySource($noteIds) as $row) {
            if ($row['to_note_id'] === null) {
                continue;
            }
            $to = (string) $row['to_note_id'];
            // Оба конца должны быть видимы, иначе ребро исключается целиком
            // (docs/api.md: «Узлы/рёбра без доступа к концу исключаются»).
            if (!isset($visible[$to])) {
                continue;
            }
            $edges[] = [
                'id' => (string) $row['id'],
                'type' => 'direct',
                'from' => (string) $row['from_note_id'],
                'to' => $to,
                'state' => 'ok',
                'label' => null,
            ];
        }

        foreach ($this->indirectLinks->findEdgesBySource($noteIds) as $row) {
            $edge = $this->describeIndirectEdge($row, $canvasId, $visible);
            if ($edge !== null) {
                $edges[] = $edge;
            }
        }

        $tags = array_map(
            static fn (array $tag): array => [
                'id' => (string) $tag['id'],
                'name' => (string) $tag['name'],
                'color' => (string) $tag['color'],
            ],
            $this->tags->findCanvasTags($canvasId),
        );

        return ['nodes' => $nodes, 'edges' => $edges, 'tags' => $tags];
    }

    /**
     * Одно правило для всех [[wikilinks]] исходной заметки:
     *  - цель видима                                → ok;
     *  - цель purged/не разрешилась (to IS NULL)    → «ссылка удалена» + label;
     *  - цель soft-deleted в том же канвасе         → «ссылка удалена» + label
     *    (маркер того, что замену можно вернуть);
     *  - цель в другом канвасе / недоступна         → исключить целиком,
     *    чтобы не раскрывать чужие заметки.
     *
     * @param array<string, mixed> $row
     * @param array<string, true>  $visible
     *
     * @return array<string, mixed>|null
     */
    private function describeIndirectEdge(array $row, string $canvasId, array $visible): ?array
    {
        $from = (string) $row['from_note_id'];
        $to = $row['to_note_id'] !== null ? (string) $row['to_note_id'] : null;
        $rawTarget = (string) $row['raw_target'];
        $resolved = (bool) $row['resolved'];

        if ($to !== null && $resolved && isset($visible[$to])) {
            return [
                'id' => (string) $row['id'],
                'type' => 'indirect',
                'from' => $from,
                'to' => $to,
                'state' => 'ok',
                'label' => null,
            ];
        }

        $targetCanvasId = $row['target_canvas_id'] !== null ? (string) $row['target_canvas_id'] : null;
        $targetDeleted = $row['target_deleted_at'] !== null;

        if ($to !== null && $targetCanvasId !== null && $targetCanvasId !== $canvasId) {
            return null;
        }

        if ($to !== null && $targetCanvasId === $canvasId && !$targetDeleted) {
            // Нода существует в этом канвасе, живая, но не попала в видимое
            // множество — скрываем существование целиком.
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'type' => 'indirect',
            'from' => $from,
            'to' => null,
            'state' => 'removed',
            'label' => $rawTarget,
        ];
    }
}
