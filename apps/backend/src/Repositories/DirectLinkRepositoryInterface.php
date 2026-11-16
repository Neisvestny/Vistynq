<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of DirectLinkRepository (roadmap R15): прямые (ручные) ссылки
 * from → to: чтение для /graph и мутации для /links/direct.
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

    /**
     * Строка ссылки по id либо null, если такой нет.
     *
     * @return array<string,mixed>|null row (id, from_note_id, to_note_id, created_by, created_at)
     */
    public function findById(string $id): ?array;

    /**
     * Существует ли прямая ссылка from → to (проверка дубля до INSERT).
     */
    public function findByEndpoints(string $fromNoteId, string $toNoteId): ?array;

    /**
     * Создать прямую ссылку. UNIQUE (from_note_id, to_note_id) → PDOException
     * 23505 при гонке; сервис превращает её в 409.
     *
     * @return array<string,mixed> row (id, from_note_id, to_note_id, created_by, created_at)
     */
    public function insert(string $fromNoteId, string $toNoteId, string $createdBy): array;

    /**
     * Удалить ссылку; false — нечего удалять (уже удалена параллельно).
     */
    public function delete(string $id): bool;

    /**
     * Разворот направления одним UPDATE. UNIQUE (from, to) → PDOException
     * 23505, если обратная ссылка уже существует.
     *
     * @return array<string,mixed> row с новым направлением
     */
    public function swap(string $id): array;
}
