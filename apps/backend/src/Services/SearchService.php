<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PDO;
use Vistynq\Support\Database;

final class SearchService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly Database $db,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function search(string $canvasId, ?string $userId, string $q): array
    {
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        if (!$ctx['role']) {
            throw \Vistynq\Support\ApiException::notFound();
        }

        $q = trim($q);
        if ($q === '') {
            return ['items' => []];
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT n.id, n.title 
             FROM notes n
             LEFT JOIN note_tags nt ON nt.note_id = n.id
             LEFT JOIN tags t ON t.id = nt.tag_id
             WHERE n.canvas_id = :canvasId AND n.deleted_at IS NULL
               AND (
                 lower(n.title) LIKE lower(:q)
                 OR lower(COALESCE(t.name,'')) LIKE lower(:q)
               )
             ORDER BY n.updated_at DESC
             LIMIT 50"
        );
        $stmt->execute(['canvasId' => $canvasId, 'q' => '%' . $q . '%']);

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => array_map(fn ($i) => ['id' => $i['id'], 'title' => $i['title']], $items)];
    }
}
