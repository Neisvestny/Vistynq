<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Repositories\CanvasRepository;
use Vistynq\Services\AccessService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;
use Vistynq\Support\Validator;

final class CanvasController
{
    public function __construct(
        private readonly CanvasRepository $canvases,
        private readonly AccessService $access,
        private readonly Database $db,
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $rows = $this->canvases->findForUser($userId);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'accessLevel' => $row['access_level'],
                'ownerId' => $row['owner_id'],
                'myRole' => $row['my_role'] ?? 'viewer',
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at'],
            ];
        }

        return Response::json(['items' => $items]);
    }

    public function create(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $this->access->assertVerified($userId);

        $data = $request->json();
        Validator::assert($data, ['title' => 'required|string|minLength:1|maxLength:200']);

        $id = $this->canvases->create($userId, (string) $data['title']);

        return Response::json(['id' => $id], 201);
    }

    public function graph(Request $request): Response
    {
        $userId = $this->optionalUserId($request);
        $canvasId = (string) $request->routeParam('id');

        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        $canvas = $ctx['canvas'];
        $myRole = $ctx['role'] ?? 'viewer';

        $pdo = $this->db->pdo();

        // Notes visible to user: owner sees all, author sees own, canvas_access grants, public_link grants
        $stmt = $pdo->prepare(
            "SELECT n.id, n.title, n.importance, n.is_relevant, n.x, n.y, n.width, n.deleted_at, n.author_id
             FROM notes n
             WHERE n.canvas_id = :canvasId AND n.deleted_at IS NULL
               AND (
                 :userId IS NOT NULL AND EXISTS (SELECT 1 FROM canvases c WHERE c.id = n.canvas_id AND c.owner_id = :userId)
                 OR (:userId IS NOT NULL AND EXISTS (SELECT 1 FROM canvas_access ca WHERE ca.canvas_id = n.canvas_id AND ca.user_id = :userId))
                 OR (:userId IS NOT NULL AND n.author_id = :userId)
                 OR (:userId IS NOT NULL AND EXISTS (SELECT 1 FROM note_access na WHERE na.note_id = n.id AND na.user_id = :userId))
                 OR (SELECT c2.access_level FROM canvases c2 WHERE c2.id = n.canvas_id) = 'public_link'
               )"
        );
        $stmt->execute(['canvasId' => $canvasId, 'userId' => $userId]);
        $notes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $noteIds = [];
        $nodes = [];
        foreach ($notes as $note) {
            $noteIds[] = $note['id'];
            $nodes[] = [
                'id' => $note['id'],
                'title' => $note['title'],
                'importance' => (int) $note['importance'],
                'isRelevant' => (bool) $note['is_relevant'],
                'x' => (float) $note['x'],
                'y' => (float) $note['y'],
                'width' => (int) $note['width'],
                'deletedAt' => $note['deleted_at'],
                'tags' => [],
                'authorId' => $note['author_id'],
            ];
        }

        // Tags for canvas
        $tagsStmt = $pdo->prepare('SELECT id, name, color FROM tags WHERE canvas_id = :canvasId ORDER BY name');
        $tagsStmt->execute(['canvasId' => $canvasId]);
        $tags = $tagsStmt->fetchAll(\PDO::FETCH_ASSOC);

        // note_tags
        $tagsByNote = [];
        if ($noteIds !== []) {
            $in = implode(',', array_fill(0, count($noteIds), '?'));
            $ntStmt = $pdo->prepare("SELECT nt.note_id, t.name FROM note_tags nt JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id IN ($in)");
            $ntStmt->execute($noteIds);
            foreach ($ntStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $tagsByNote[$row['note_id']][] = $row['name'];
            }
        }
        foreach ($nodes as $idx => $node) {
            $nodes[$idx]['tags'] = $tagsByNote[$node['id']] ?? [];
        }

        // Direct links - both ends must be visible
        $edges = [];
        if ($noteIds !== []) {
            $in = implode(',', array_fill(0, count($noteIds), '?'));
            $dlStmt = $pdo->prepare("SELECT id, from_note_id, to_note_id FROM direct_links WHERE from_note_id IN ($in) AND to_note_id IN ($in)");
            $dlStmt->execute($noteIds);
            foreach ($dlStmt->fetchAll(\PDO::FETCH_ASSOC) as $dl) {
                $edges[] = [
                    'id' => $dl['id'],
                    'type' => 'direct',
                    'from' => $dl['from_note_id'],
                    'to' => $dl['to_note_id'],
                    'state' => 'ok',
                ];
            }

            // Indirect links
            $ilStmt = $pdo->prepare("SELECT id, from_note_id, to_note_id, resolved FROM indirect_links WHERE from_note_id IN ($in)");
            $ilStmt->execute($noteIds);
            $visibleSet = array_flip($noteIds);
            foreach ($ilStmt->fetchAll(\PDO::FETCH_ASSOC) as $il) {
                if ($il['to_note_id'] !== null && isset($visibleSet[$il['to_note_id']]) && $il['resolved']) {
                    $edges[] = [
                        'id' => $il['id'],
                        'type' => 'indirect',
                        'from' => $il['from_note_id'],
                        'to' => $il['to_note_id'],
                        'state' => 'ok',
                    ];
                } else {
                    $edges[] = [
                        'id' => $il['id'],
                        'type' => 'indirect',
                        'from' => $il['from_note_id'],
                        'to' => null,
                        'state' => 'removed',
                    ];
                }
            }
        }

        return Response::json([
            'canvas' => [
                'id' => $canvas['id'],
                'title' => $canvas['title'],
                'access' => ['level' => $canvas['access_level'], 'myRole' => $myRole],
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'tags' => array_map(fn ($t) => ['id' => $t['id'], 'name' => $t['name'], 'color' => $t['color']], $tags),
        ]);
    }

    public function update(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        if (!$this->access->canEditCanvas($ctx['role'])) {
            throw ApiException::notFound();
        }

        $data = $request->json();
        $this->canvases->update($canvasId, $data);

        return Response::json(['ok' => true]);
    }

    public function delete(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        if (!$this->access->canManageCanvas($ctx['role'])) {
            throw ApiException::notFound();
        }

        $this->canvases->delete($canvasId);

        return Response::json(['ok' => true]);
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
