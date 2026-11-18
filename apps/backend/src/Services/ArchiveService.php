<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\Note;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Support\ApiException;
use Vistynq\Support\Pagination;

/**
 * Архив канваса (docs/api.md §Канвасы, ROADMAP R13): обязательный
 * ?section=notRelevant|deleted, ответ {items:[Note], page, perPage, total}
 * (R19).
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
     * @return array{items: list<array<string, mixed>>, page: int, perPage: int, total: int}
     */
    public function get(string $canvasId, string $userId, string $section, Pagination $pagination): array
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

        $result = $section === 'notRelevant'
            ? $this->notes->findNotRelevant($canvasId, $userId, $pagination->limit(), $pagination->offset())
            : $this->notes->findDeleted($canvasId, $userId, $pagination->limit(), $pagination->offset());

        // Порядок задан в SQL: notRelevant — updated_at DESC, deleted —
        // deleted_at DESC, оба с tiebreaker по id (стабильная пагинация).
        $items = array_map(
            static fn (array $row): array => Note::fromRow($row)->toArray(),
            $result['items'],
        );

        return $pagination->envelope($items, $result['total']);
    }
}
