<?php

declare(strict_types=1);

use Vistynq\Repositories\DirectLinkRepositoryInterface;

/**
 * In-memory fake for GraphService/LinkService unit tests (roadmap R15/R17).
 */
final class FakeDirectLinkRepository implements DirectLinkRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    private array $edges = [];

    /**
     * @param array<string,mixed> $edge row (id, from_note_id, to_note_id)
     */
    public function add(array $edge): void
    {
        $this->edges[] = $edge;
    }

    public function findEdgesBySource(array $fromNoteIds): array
    {
        $sources = array_flip($fromNoteIds);

        return array_values(array_filter(
            $this->edges,
            static fn (array $edge): bool => isset($sources[(string) $edge['from_note_id']]),
        ));
    }

    public function findById(string $id): ?array
    {
        foreach ($this->edges as $edge) {
            if ((string) $edge['id'] === $id) {
                return $edge;
            }
        }

        return null;
    }

    public function findByEndpoints(string $fromNoteId, string $toNoteId): ?array
    {
        foreach ($this->edges as $edge) {
            if ((string) $edge['from_note_id'] === $fromNoteId && (string) $edge['to_note_id'] === $toNoteId) {
                return $edge;
            }
        }

        return null;
    }

    public function insert(string $fromNoteId, string $toNoteId, string $createdBy): array
    {
        if ($this->findByEndpoints($fromNoteId, $toNoteId) !== null) {
            // UNIQUE (from_note_id, to_note_id) — как в Postgres (23505)
            throw new PDOException('duplicate key value violates unique constraint', 23505);
        }

        $edge = [
            'id' => $this->uuid(),
            'from_note_id' => $fromNoteId,
            'to_note_id' => $toNoteId,
            'created_by' => $createdBy,
            'created_at' => gmdate('c'),
        ];
        $this->edges[] = $edge;

        return $edge;
    }

    public function delete(string $id): bool
    {
        $before = count($this->edges);
        $this->edges = array_values(array_filter(
            $this->edges,
            static fn (array $edge): bool => (string) $edge['id'] !== $id,
        ));

        return count($this->edges) < $before;
    }

    public function swap(string $id): array
    {
        foreach ($this->edges as &$edge) {
            if ((string) $edge['id'] !== $id) {
                continue;
            }

            $from = (string) $edge['from_note_id'];
            $to = (string) $edge['to_note_id'];
            if ($this->findByEndpoints($to, $from) !== null) {
                throw new PDOException('duplicate key value violates unique constraint', 23505);
            }
            $edge['from_note_id'] = $to;
            $edge['to_note_id'] = $from;

            return $edge;
        }
        unset($edge);

        throw new RuntimeException('direct link not found: ' . $id);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
