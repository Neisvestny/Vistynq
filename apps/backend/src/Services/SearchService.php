<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\Note;
use Vistynq\Repositories\NoteRepositoryInterface;

/**
 * Поиск по заголовкам и тегам внутри канваса (docs/api.md).
 * Регистронезависимый LIKE с экранированием %/_ — литеральный ввод.
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
            static fn (array $row): array => Note::fromRow($row)->toArray(),
            $this->notes->search($canvasId, $q),
        );

        return ['items' => $items];
    }
}
