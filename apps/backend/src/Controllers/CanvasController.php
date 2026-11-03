<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Models\Canvas;
use Vistynq\Repositories\CanvasRepository;
use Vistynq\Repositories\DirectLinkRepository;
use Vistynq\Repositories\IndirectLinkRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Repositories\TagRepository;
use Vistynq\Services\AccessService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

final class CanvasController
{
    public function __construct(
        private readonly CanvasRepository $canvases,
        private readonly NoteRepository $notes,
        private readonly TagRepository $tags,
        private readonly DirectLinkRepository $directLinks,
        private readonly IndirectLinkRepository $indirectLinks,
        private readonly AccessService $access,
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $rows = $this->canvases->findForUser($userId);

        return Response::json([
            'items' => array_map(
                static fn (array $row): array => Canvas::fromRow($row)->toArray(),
                $rows,
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $this->access->assertVerified($userId);

        $title = self::readTitle($request->json(), required: true);
        $row = $this->canvases->create($userId, $title);

        return Response::json(Canvas::fromRow($row, 'owner')->toArray(), 201);
    }

    /**
     * GET /canvases/{id}/graph — узлы, рёбра, теги, права (docs/api.md).
     *
     * SQL живёт в репозиториях (docs/architecture.md §4): контроллер только
     * сворачивает выборки в публикуемую форму.
     */
    public function graph(Request $request): Response
    {
        $userId = AuthMiddleware::optionalUserId($request);
        $canvasId = (string) $request->routeParam('id');

        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        $canvas = Canvas::fromRow($ctx['canvas'], $ctx['role']);

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

        return Response::json([
            'canvas' => $canvas->toArray(),
            'nodes' => $nodes,
            'edges' => $edges,
            'tags' => $tags,
        ]);
    }

    public function update(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $ctx = $this->access->requireCanvasEdit($canvasId, $userId);

        $data = $request->json();
        if (array_key_exists('title', $data)) {
            $this->canvases->update($canvasId, ['title' => self::readTitle($data, required: true)]);
        }

        $row = $this->canvases->findById($canvasId);

        return Response::json(Canvas::fromRow($row ?? $ctx['canvas'], $ctx['role'])->toArray());
    }

    public function delete(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $this->access->requireCanvasManage($canvasId, $userId);

        $this->canvases->delete($canvasId);

        return Response::noContent();
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

    /**
     * Заголовок канваса: непустая строка ≤200 симв. (docs/openapi.yaml).
     */
    private static function readTitle(array $data, bool $required): string
    {
        Validator::assert($data, ['title' => ($required ? 'required|' : '') . 'string|maxLength:200']);

        $title = trim((string) $data['title']);
        if ($title === '') {
            throw ApiException::validation(['title' => ['обязательное поле']]);
        }

        return $title;
    }
}
