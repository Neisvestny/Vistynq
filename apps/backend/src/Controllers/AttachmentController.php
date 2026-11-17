<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\AttachmentService;

/**
 * HTTP-обёртка вложений заметок (docs/api.md §Вложения, R17).
 */
final class AttachmentController
{
    public function __construct(private readonly AttachmentService $attachments)
    {
    }

    public function uploadNote(Request $request): Response
    {
        $attachment = $this->attachments->uploadToNote(
            (string) $request->routeParam('id'),
            AuthMiddleware::userId($request),
            $request->multipartFile('file'),
        );

        return Response::json($attachment, 201);
    }

    public function url(Request $request): Response
    {
        return Response::json($this->attachments->url(
            (string) $request->routeParam('id'),
            AuthMiddleware::userId($request),
        ));
    }

    public function delete(Request $request): Response
    {
        $this->attachments->delete(
            (string) $request->routeParam('id'),
            AuthMiddleware::userId($request),
        );

        return Response::noContent();
    }
}
