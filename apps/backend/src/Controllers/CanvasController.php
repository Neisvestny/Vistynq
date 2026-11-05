<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Models\Canvas;
use Vistynq\Repositories\CanvasRepositoryInterface;
use Vistynq\Services\AccessService;
use Vistynq\Services\GraphService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

/**
 * Тонкий HTTP-слой над сервисами и репозиториями (roadmap R17):
 * валидация входа, вызов сервисов, формирование Response. Сборка graph
 * вынесена в GraphService, права — в AccessService.
 */
final class CanvasController
{
    public function __construct(
        private readonly CanvasRepositoryInterface $canvases,
        private readonly AccessService $access,
        private readonly GraphService $graph,
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
     */
    public function graph(Request $request): Response
    {
        $userId = AuthMiddleware::optionalUserId($request);
        $canvasId = (string) $request->routeParam('id');

        // Права и 404/403 — AccessService; сборка payload'а — GraphService.
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        $canvas = Canvas::fromRow($ctx['canvas'], $ctx['role']);

        $graph = $this->graph->build($canvasId, $userId);

        return Response::json([
            'canvas' => $canvas->toArray(),
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
            'tags' => $graph['tags'],
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
