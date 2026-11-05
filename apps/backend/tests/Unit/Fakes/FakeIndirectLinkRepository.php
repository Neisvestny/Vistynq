<?php

declare(strict_types=1);

use Vistynq\Repositories\IndirectLinkRepositoryInterface;

/**
 * In-memory fake for GraphService unit tests (roadmap R15/R17).
 */
final class FakeIndirectLinkRepository implements IndirectLinkRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    private array $edges = [];

    /**
     * @param array<string,mixed> $edge row (id, from_note_id, to_note_id, raw_target,
     *                                     resolved, target_canvas_id, target_deleted_at)
     */
    public function add(array $edge): void
    {
        $this->edges[] = $edge;
    }

    public function deleteForSource(string $fromNoteId): void
    {
        $this->edges = array_values(array_filter(
            $this->edges,
            static fn (array $edge): bool => $edge['from_note_id'] !== $fromNoteId,
        ));
    }

    public function insert(string $fromNoteId, ?string $toNoteId, string $rawTarget, bool $resolved): void
    {
        $this->edges[] = [
            'id' => $this->uuid(),
            'from_note_id' => $fromNoteId,
            'to_note_id' => $toNoteId,
            'raw_target' => $rawTarget,
            'resolved' => $resolved,
            'target_canvas_id' => null,
            'target_deleted_at' => null,
        ];
    }

    public function findEdgesBySource(array $fromNoteIds): array
    {
        $sources = array_flip($fromNoteIds);

        return array_values(array_filter(
            $this->edges,
            static fn (array $edge): bool => isset($sources[(string) $edge['from_note_id']]),
        ));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
