<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\Note;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Support\ApiException;

/**
 * Архив канваса (docs/api.md §Канвасы, ROADMAP R13): обязательный
 * ?section=notRelevant|deleted, ответ {items:[Note]}.
 *
 * notRelevant — живые is_relevant=false; deleted — только soft-deleted
 * заметки (стёртые навсегда, tombstone'ы, сюда не попадают —
 * docs/api.md §archive).
 */
final class ArchiveService
{
    public const SECTIONS = ['notRelevant', 'deleted'];

    public function __construct(
        private readonly NoteRepositoryInterface $notes,
        private readonly AccessService $access,
    ) {
    }

    /**
     * @return array<string, mixed> {items: list<array<string, mixed>>}
     */
    public function get(string $canvasId, string $userId, string $section): array
    {
        // Сначала права (404/403 не подтверждают существование канваса),
        // затем валидация — как в NoteService::create.
        // ≥editor (docs/api.md): viewer видит канвас, но не архив → 403, не 404.
        $this->access->requireCanvasEdit($canvasId, $userId);

        if (!in_array($section, self::SECTIONS, true)) {
            throw ApiException::validation(
                ['section' => ['обязателен и должен быть notRelevant или deleted']],
                'Некорректный section',
            );
        }

        $rows = $section === 'notRelevant'
            ? $this->notes->findNotRelevant($canvasId, $userId)
            : $this->notes->findDeleted($canvasId, $userId);

        $items = array_map(
            static fn (array $row): array => Note::fromRow($row)->toArray(),
            $rows,
        );

        if ($section === 'deleted') {
            // Уже отсортировано по deleted_at DESC в SQL, но дублируем
            // явно: контракт — «по deletedAt DESC» (docs/api.md).
            usort($items, static function (array $a, array $b): int {
                $left = strtotime((string) ($a['deletedAt'] ?? '')) ?: 0;
                $right = strtotime((string) ($b['deletedAt'] ?? '')) ?: 0;

                return $right <=> $left;
            });
        }

        return ['items' => $items];
    }
}
