<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\ArchiveService;
use Vistynq\Services\NoteService;
use Vistynq\Services\SearchService;

/**
 * HTTP-обёртка над NoteService/ArchiveService/SearchService: статусы и тела
 * по docs/openapi.yaml (201 Note, 200 NoteDetail/Note, 204 без тела).
 */
final class NoteController
{
    public function __construct(
        private readonly NoteService $notes,
        private readonly ArchiveService $archive,
        private readonly SearchService $search,
    ) {
    }

    public function create(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');

        $note = $this->notes->create($canvasId, $userId, $request->json());

        return Response::json($note, 201);
    }

    public function show(Request $request): Response
    {
        $userId = AuthMiddleware::optionalUserId($request);
        $noteId = (string) $request->routeParam('id');

        return Response::json($this->notes->get($noteId, $userId));
    }

    public function update(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        return Response::json($this->notes->updateManifest($noteId, $userId, $request->json()));
    }

    public function updateBody(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        return Response::json($this->notes->updateBody($noteId, $userId, $request->json()));
    }

    public function updatePosition(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $noteId = (string) $request->routeParam('noteId');

        $this->notes->updatePosition($canvasId, $noteId, $userId, $request->json());

        return Response::noContent();
    }

    public function delete(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        $this->notes->softDelete($noteId, $userId);

        return Response::noContent();
    }

    public function restore(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        return Response::json($this->notes->restore($noteId, $userId));
    }

    public function purge(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        $this->notes->purge($noteId, $userId);

        return Response::noContent();
    }

    public function archive(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');

        return Response::json($this->archive->get($canvasId, $userId));
    }

    public function search(Request $request): Response
    {
        $userId = AuthMiddleware::optionalUserId($request);
        $canvasId = (string) $request->routeParam('id');
        $q = (string) $request->query('q', '');

        return Response::json($this->search->search($canvasId, $userId, $q));
    }
}
