<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of DirectLinkRepository (roadmap R17): outgoing direct links
 * ([[uuid]]-цели) of source notes.
 */
interface DirectLinkRepositoryInterface
{
    /**
     * Outgoing direct links of the given source notes.
     *
     * Only the source side is filtered in SQL: the caller already knows the
     * set of notes visible to the user and drops edges whose target is not
     * in it.
     *
     * @param list<string> $fromNoteIds
     *
     * @return list<array<string,mixed>>
     */
    public function findEdgesBySource(array $fromNoteIds): array;
}
