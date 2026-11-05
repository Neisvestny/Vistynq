<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of TagRepository (roadmap R17): services/controllers depend on
 * this instead of the concrete class, unit tests substitute in-memory fakes.
 */
interface TagRepositoryInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * @param list<string> $ids
     *
     * @return list<array<string,mixed>> rows of the given ids (id, canvas_id, name)
     */
    public function findManyByIds(array $ids): array;

    /**
     * All tags of a canvas (graph payload + tag pickers).
     *
     * @return list<array<string,mixed>>
     */
    public function findCanvasTags(string $canvasId): array;

    /**
     * Новый тег канваса. Нарушение UNIQUE (canvas_id, name) не перехватывается
     * здесь — его ловит TagService и превращает в 409.
     *
     * @return array<string, mixed> row (id, canvas_id, name, color, created_at)
     */
    public function create(string $canvasId, string $name, string $color): array;

    /**
     * Частичное обновление тега (только переданные поля).
     *
     * @param array<string, string> $fields name и/или color
     *
     * @return array<string, mixed>|null null, если тег исчез между чтением и записью
     */
    public function update(string $id, array $fields): ?array;

    /**
     * Hard delete тега: note_tags уходят каскадом (FK ON DELETE CASCADE).
     */
    public function delete(string $id): void;

    /**
     * note_id → tag names, resolved in one query scoped to the canvas.
     *
     * @return array<string, list<string>> noteId => [tagName, ...]
     */
    public function findNoteTagNames(string $canvasId): array;
}
