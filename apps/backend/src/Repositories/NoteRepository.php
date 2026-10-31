<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class NoteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM notes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
