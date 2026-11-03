<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PDOException;
use Vistynq\Repositories\TagRepository;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

/**
 * Пер-канвасные теги (docs/api.md §Теги, docs/ROADMAP.md R13).
 *
 * Права: список — ≥viewer (getCanvasContext); мутации — ≥editor
 * (requireCanvasEdit) плюс верифицированный email. Тег не имеет собственных
 * прав — роль берётся от его канваса, поэтому 403/404 решает AccessService.
 *
 * Уникальность имени — UNIQUE (canvas_id, name) с citext (регистронезависимо);
 * нарушение 23505 превращается в 409 без предварительной проверки (гонка).
 *
 * Переименование/перекраска не требуют отдельных UPDATE по заметкам: заметки
 * видят тег через join note_tags, изменения отражаются на всех сразу.
 */
final class TagService
{
    private const DEFAULT_COLOR = '#7c3aed';

    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        private readonly TagRepository $tags,
        private readonly AccessService $access,
    ) {
    }

    /**
     * @return list<array<string, mixed>> [{id, name, color}], сортировка по имени
     */
    public function list(string $canvasId, ?string $userId): array
    {
        $this->access->getCanvasContext($canvasId, $userId);

        return array_map(
            static fn (array $row): array => self::toArray($row),
            $this->tags->findCanvasTags($canvasId),
        );
    }

    /**
     * @param array<string, mixed> $data {name, color?}
     *
     * @return array<string, mixed> Tag (201)
     */
    public function create(string $canvasId, string $userId, array $data): array
    {
        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit($canvasId, $userId);

        Validator::assert($data, ['name' => 'required|string|maxLength:50']);
        $name = trim((string) $data['name']);
        if ($name === '') {
            throw ApiException::validation(['name' => ['обязательное поле']]);
        }

        $color = self::DEFAULT_COLOR;
        if (array_key_exists('color', $data)) {
            $color = self::readColor($data['color']);
        }

        try {
            $row = $this->tags->create($canvasId, $name, $color);
        } catch (PDOException $e) {
            self::rethrowAsConflictIfDuplicate($e);
        }

        return self::toArray($row);
    }

    /**
     * PATCH: минимум одно из {name, color}; пустое тело — no-op (200 с тем же
     * тегом), как у PATCH /canvases/{id}.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> Tag (200)
     */
    public function update(string $tagId, string $userId, array $data): array
    {
        $tag = $this->tags->findById($tagId);
        if ($tag === null) {
            throw ApiException::notFound();
        }

        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit((string) $tag['canvas_id'], $userId);

        $fields = [];
        if (array_key_exists('name', $data)) {
            Validator::assert($data, ['name' => 'required|string|maxLength:50']);
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw ApiException::validation(['name' => ['обязательное поле']]);
            }
            $fields['name'] = $name;
        }
        if (array_key_exists('color', $data)) {
            $fields['color'] = self::readColor($data['color']);
        }

        if ($fields === []) {
            // нет полей для правки — no-op
            return self::toArray($tag);
        }

        try {
            $row = $this->tags->update($tagId, $fields);
        } catch (PDOException $e) {
            self::rethrowAsConflictIfDuplicate($e);
        }

        return self::toArray($row ?? $tag);
    }

    /**
     * Hard delete: note_tags уходят каскадом (тег снимается со всех заметок).
     */
    public function delete(string $tagId, string $userId): void
    {
        $tag = $this->tags->findById($tagId);
        if ($tag === null) {
            throw ApiException::notFound();
        }

        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit((string) $tag['canvas_id'], $userId);

        $this->tags->delete($tagId);
    }

    /**
     * Публичная форма тега по docs/openapi.yaml → Tag.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function toArray(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'color' => (string) $row['color'],
        ];
    }

    /**
     * Цвет: строка вида #rrggbb — иначе 422 (в openapi → pattern).
     */
    private static function readColor(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::COLOR_PATTERN, $value) !== 1) {
            throw ApiException::validation(['color' => ['ожидается цвет в формате #rrggbb']]);
        }

        return $value;
    }

    /**
     * 23505 (UNIQUE) → 409 CONFLICT; любая другая ошибка БД — наружу как 500.
     */
    private static function rethrowAsConflictIfDuplicate(PDOException $e): never
    {
        if ((string) $e->getCode() === '23505') {
            throw ApiException::conflict(
                'Тег с таким именем уже существует',
                ['name' => ['дубль имени тега в этом канвасе']],
            );
        }

        throw $e;
    }
}
