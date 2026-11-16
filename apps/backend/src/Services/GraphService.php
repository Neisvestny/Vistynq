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
     * @param string      $role     роль вызывающего: owner/editor/viewer/none
     *
     * @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>, tags: list<array<string,mixed>>}
     */
    public function build(string $canvasId, ?string $userId, string $role): array
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
            $targetCanvasId = $row['target_canvas_id'] ?? null;
            $targetDeleted = ($row['target_deleted_at'] ?? null) !== null;

            // Кросс-канвасная цель не раскрывается вовсе (прямые всегда того
            // же канваса — но защищаемся от строк, созданных в обход API).
            if ($targetCanvasId !== null && $targetCanvasId !== $canvasId) {
                continue;
            }

            if (!$targetDeleted) {
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
                continue;
            }

            // Прямая ссылка на soft-deleted цель: маркер «ссылка удалена»
            // с to = id (чтобы предложить «восстановить») — только авторизованным
            // и ≥editor (docs/api.md §graph, R12).
            if ($userId === null || !$this->maySeeSoftDeletedTarget($role)) {
                continue;
            }
            $edges[] = [
                'id' => (string) $row['id'],
                'type' => 'direct',
                'from' => (string) $row['from_note_id'],
                'to' => $to,
                'state' => 'removed',
                'label' => null,
            ];
        }

        foreach ($this->indirectLinks->findEdgesBySource($noteIds) as $row) {
            $edge = $this->describeIndirectEdge($row, $canvasId, $visible, $userId, $role);
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
     *  - цель в другом канвасе / живая, но невидима → исключить целиком,
     *    чтобы не раскрывать чужие заметки;
     *  - цель не найдена и не стиралась (опечатка,  → ребра нет вовсе,
     *    переименование: deleted_target_id IS NULL)    чип в тексте остаётся
     *    (docs/data-model.md:147);
     *  - цель purged (deleted_target_id задан)      → «ссылка удалена»
     *    (to = null) + label, только авторизованным (R12);
     *  - цель soft-deleted в том же канвасе         → «ссылка удалена»
     *    с to = id (чтобы предложить «восстановить»), только ≥editor.
     *
     * @param array<string, mixed> $row
     * @param array<string, true>  $visible
     *
     * @return array<string, mixed>|null
     */
    private function describeIndirectEdge(array $row, string $canvasId, array $visible, ?string $userId, string $role): ?array
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

        // Опечатка/переименование: цель не найдена и не стиралась
        // (deleted_target_id IS NULL) — ребра на графе нет, в тексте остаётся
        // обычный чип (docs/data-model.md:147).
        if ($to === null && ($row['deleted_target_id'] ?? null) === null) {
            return null;
        }

        // Пометки «ссылка удалена» — только авторизованным пользователям,
        // которые видят заметку-источник (docs/architecture.md §5; аноним
        // на публичном канвасе пометки не получает).
        if ($userId === null) {
            return null;
        }

        // Стёртая навсегда цель: to = null, deleted_target_id задан
        // (purge пишет его в той же транзакции, data-model.md:148).
        if ($to === null || $targetCanvasId === null) {
            return [
                'id' => (string) $row['id'],
                'type' => 'indirect',
                'from' => $from,
                'to' => null,
                'state' => 'removed',
                'label' => $rawTarget,
            ];
        }

        // Soft-deleted цель в этом же канвасе: to = id цели, только ≥editor —
        // только им положено предложение «восстановить» (docs/api.md §graph).
        if (!$this->maySeeSoftDeletedTarget($role)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'type' => 'indirect',
            'from' => $from,
            'to' => $to,
            'state' => 'removed',
            'label' => $rawTarget,
        ];
    }

    /**
     * Маркер soft-deleted цели (to = id) показывается только ≥editor канваса
     * (docs/api.md §graph: «видно только ≥editor»); роль 'none' и viewer —
     * нет. Авторизацию проверяет вызывающий код.
     */
    private function maySeeSoftDeletedTarget(string $role): bool
    {
        return $role === 'owner' || $role === 'editor';
    }
}
