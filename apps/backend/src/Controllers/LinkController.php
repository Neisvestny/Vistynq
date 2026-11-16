<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\LinkService;

/**
 * HTTP-обёртка над LinkService (docs/api.md §Ссылки, R15):
 * 201 DirectLink, 204 без тела, статусы — сервис.
 */
final class LinkController
{
    public function __construct(private readonly LinkService $links)
    {
    }

    public function create(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);

        return Response::json($this->links->create($userId, $request->json()), 201);
    }

    public function delete(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $linkId = (string) $request->routeParam('id');

        $this->links->delete($userId, $linkId);

        return Response::noContent();
    }

    public function reverse(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $linkId = (string) $request->routeParam('id');

        return Response::json($this->links->reverse($userId, $linkId));
    }
}
