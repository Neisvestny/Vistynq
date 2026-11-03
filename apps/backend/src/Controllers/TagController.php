<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\TagService;

/**
 * HTTP-обёртка над TagService: статусы и тела по docs/openapi.yaml
 * (200-массив, 201/200 Tag, 204 без тела).
 */
final class TagController
{
    public function __construct(private readonly TagService $tags)
    {
    }

    public function index(Request $request): Response
    {
        $userId = AuthMiddleware::optionalUserId($request);
        $canvasId = (string) $request->routeParam('id');

        return Response::json($this->tags->list($canvasId, $userId));
    }

    public function create(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');

        $tag = $this->tags->create($canvasId, $userId, $request->json());

        return Response::json($tag, 201);
    }

    public function update(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $tagId = (string) $request->routeParam('id');

        return Response::json($this->tags->update($tagId, $userId, $request->json()));
    }

    public function delete(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $tagId = (string) $request->routeParam('id');

        $this->tags->delete($tagId, $userId);

        return Response::noContent();
    }
}
