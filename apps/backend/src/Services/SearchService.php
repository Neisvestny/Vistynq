<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\NoteRepositoryInterface;

/**
 * Поиск по заголовкам и тегам внутри канваса (docs/api.md).
 * Регистронезависимый LIKE с экранированием %/_ — литеральный ввод.
 * Ответ — SearchHit {id, title, importance, matchedTag} (docs/openapi.yaml).
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
    public function search(string $canvasId, ?string $userId, string $q): array
    {
        $this->access->getCanvasContext($canvasId, $userId);

        $q = trim($q);
        if ($q === '') {
            return ['items' => []];
        }

        $items = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'importance' => (int) $row['importance'],
                'matchedTag' => isset($row['matched_tag']) && $row['matched_tag'] !== null
                    ? (string) $row['matched_tag']
                    : null,
            ],
            $this->notes->search($canvasId, $userId, $q),
        );

        return ['items' => $items];
    }
}
