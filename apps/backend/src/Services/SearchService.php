<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Support\Pagination;

/**
 * Поиск по заголовкам и тегам внутри канваса (docs/api.md).
 * Регистронезависимый LIKE с экранированием %/_ — литеральный ввод.
 * Ответ — {items: SearchHit[], page, perPage, total} (docs/openapi.yaml, R19).
 */
final class SearchService
{
    public function __construct(
        private readonly NoteRepositoryInterface $notes,
        private readonly AccessService $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $canvasId, ?string $userId, string $q, Pagination $pagination): array
    {
        $this->access->getCanvasContext($canvasId, $userId);

        $q = trim($q);
        if ($q === '') {
            return $pagination->envelope([], 0);
        }

        $result = $this->notes->search(
            $canvasId,
            $userId,
            $q,
            $pagination->limit(),
            $pagination->offset(),
        );

        $items = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'importance' => (int) $row['importance'],
                'matchedTag' => isset($row['matched_tag']) && $row['matched_tag'] !== null
                    ? (string) $row['matched_tag']
                    : null,
            ],
            $result['items'],
        );

        return $pagination->envelope($items, $result['total']);
    }
}
