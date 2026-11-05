<?php

declare(strict_types=1);

use Vistynq\Repositories\DirectLinkRepositoryInterface;

/**
 * In-memory fake for GraphService unit tests (roadmap R15/R17).
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
}
