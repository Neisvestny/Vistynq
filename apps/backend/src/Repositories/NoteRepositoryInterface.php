<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of NoteRepository (roadmap R17): services/controllers depend on
 * this instead of the concrete class, unit tests substitute in-memory fakes
 * (roadmap R15).
 */
interface NoteRepositoryInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array;

    public function create(string $canvasId, string $authorId, string $title, float $x, float $y): string;

    /**
     * @param array<string,mixed> $fields
     */
    public function update(string $id, array $fields): void;

    public function updateBody(string $id, string $bodyMd): void;

    /**
     * Idempotent: an already deleted note keeps its original deleted_at.
     */
    public function softDelete(string $id): void;

    public function restore(string $id): void;

    /**
     * Hard delete (purge): tombstone в deleted_notes создаёт вызывающий код
     * до этого вызова (в той же транзакции).
     */
    public function hardDelete(string $id): void;

    /**
     * Role from note_access invite row, or null when no invite exists.
     */
    public function findNoteAccess(string $noteId, string $userId): ?string;

    /**
     * Nodes of GET /canvases/{id}/graph (visibility predicate inside).
     *
     * @return list<array<string,mixed>>
     */
    public function findGraphNotes(string $canvasId, ?string $userId): array;

    /**
     * Archive «неактуально» (docs/data-model.md: is_relevant = false, живые).
     *
     * @return list<array<string,mixed>>
     */
    public function findNotRelevant(string $canvasId): array;

    /**
     * Archive «удалённые» — soft-deleted заметки (tombstone'ы purge приходят
     * отдельно из DeletedNoteRepository).
     *
     * @return list<array<string,mixed>>
     */
    public function findDeleted(string $canvasId): array;

    /**
     * Поиск по заголовкам и тегам в рамках канваса (docs/api.md).
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $canvasId, string $q): array;

    /**
     * Резолв [[uuid]]-цели: только живые заметки этого канваса.
     *
     * @param list<string> $ids
     *
     * @return list<string> подмножество $ids
     */
    public function findVisibleIdsInCanvas(string $canvasId, array $ids): array;

    /**
     * Резолв [[Заголовок]]-цели одним запросом (регистронезависимо).
     *
     * @param list<string> $titles
     *
     * @return array<string, string>
     */
    public function findVisibleByLowerTitles(string $canvasId, array $titles): array;

    /**
     * @return list<array<string,mixed>>
     */
    public function findTags(string $noteId): array;

    /**
     * @param list<mixed> $tagIds
     */
    public function syncTags(string $noteId, array $tagIds): void;
}
