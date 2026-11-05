<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of IndirectLinkRepository (roadmap R17): outgoing wikilinks
 * ([[Заголовок]]-цели) of source notes.
 */
interface IndirectLinkRepositoryInterface
{
    public function deleteForSource(string $fromNoteId): void;

    public function insert(string $fromNoteId, ?string $toNoteId, string $rawTarget, bool $resolved): void;

    /**
     * Outgoing wikilinks of the given source notes, with the target's canvas
     * and deletion state so the caller can tell apart:
     *  - target missing (purged / never resolved)  → «ссылка удалена»
     *  - target visible                             → edge ok
     *  - target soft-deleted in the same canvas     → «ссылка удалена» (restore marker)
     *  - target in another canvas                   → edge dropped entirely
     *
     * @param list<string> $fromNoteIds
     *
     * @return list<array<string,mixed>>
     */
    public function findEdgesBySource(array $fromNoteIds): array;
}
