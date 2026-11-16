<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Services\SharedService;

/**
 * Публичные read-only страницы (docs/api.md §Публичный доступ, R16).
 * Без Bearer: маршруты /api/v1/shared объявлены публичными в AuthMiddleware.
 */
final class SharedController
{
    public function __construct(private readonly SharedService $shared)
    {
    }

    public function canvas(Request $request): Response
    {
        return Response::json($this->shared->canvas((string) $request->routeParam('slug')));
    }

    public function note(Request $request): Response
    {
        return Response::json($this->shared->note(
            (string) $request->routeParam('slug'),
            (string) $request->routeParam('noteId'),
        ));
    }
}
