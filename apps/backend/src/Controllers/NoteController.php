<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\ArchiveService;
use Vistynq\Services\NoteService;
use Vistynq\Services\SearchService;
use Vistynq\Support\Validator;

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
        $data = $request->json();
        $title = isset($data['title']) && is_string($data['title']) ? $data['title'] : null;
        $x = isset($data['x']) ? (float) $data['x'] : 0;
        $y = isset($data['y']) ? (float) $data['y'] : 0;

        $id = $this->notes->create($canvasId, $userId, $title, $x, $y);

        return Response::json(['id' => $id], 201);
    }

    public function show(Request $request): Response
    {
        $userId = $this->optionalUserId($request);
        $noteId = (string) $request->routeParam('id');

        $result = $this->notes->get($noteId, $userId);

        return Response::json($result);
    }

    public function update(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        $data = $request->json();

        $this->notes->updateManifest($noteId, $userId, $data);

        return Response::json(['ok' => true]);
    }

    public function updateBody(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        $data = $request->json();
        Validator::assert($data, ['bodyMd' => 'required|string']);

        $this->notes->updateBody($noteId, $userId, (string) $data['bodyMd']);

        return Response::json(['ok' => true]);
    }

    public function updatePosition(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $noteId = (string) $request->routeParam('noteId');
        $data = $request->json();

        $x = array_key_exists('x', $data) ? (float) $data['x'] : null;
        $y = array_key_exists('y', $data) ? (float) $data['y'] : null;
        $width = array_key_exists('width', $data) ? (int) $data['width'] : null;

        $this->notes->updatePosition($canvasId, $noteId, $userId, $x, $y, $width);

        return Response::json(['ok' => true]);
    }

    public function delete(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        $this->notes->softDelete($noteId, $userId);

        return Response::json(['ok' => true]);
    }

    public function restore(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        $this->notes->restore($noteId, $userId);

        return Response::json(['ok' => true]);
    }

    public function purge(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        $this->notes->purge($noteId, $userId);

        return Response::json(['ok' => true]);
    }

    public function archive(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');

        $result = $this->archive->get($canvasId, $userId);

        return Response::json($result);
    }

    public function search(Request $request): Response
    {
        $userId = $this->optionalUserId($request);
        $canvasId = (string) $request->routeParam('id');
        $q = (string) $request->query('q', '');

        $result = $this->search->search($canvasId, $userId, $q);

        return Response::json($result);
    }

    private function optionalUserId(Request $request): ?string
    {
        try {
            return AuthMiddleware::userId($request);
        } catch (\Throwable) {
            return null;
        }
    }
}
