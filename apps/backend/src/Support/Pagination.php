<?php

declare(strict_types=1);

namespace Vistynq\Support;

use Vistynq\Http\Request;

/**
 * Пагинация списков (R19): `?page=1&perPage=50`.
 *
 * Дефолты — 1/50, максимум perPage — 200 (docs/api.md). Невалидные значения
 * дают 422 VALIDATION_FAILED через общий Validator, а не молчаливый clamp:
 * клиент должен видеть, что его параметры проигнорированы не будут.
 *
 * `page` за последней страницей — не ошибка: репозиторий вернёт пустой
 * `items`, а `total` останется общим числом элементов.
 */
final class Pagination
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        // Request::query() отдаёт только строки; массивы (`page[]=1`) считаем
        // отсутствующими параметрами и применяем дефолт.
        $data = [];
        foreach (['page', 'perPage'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $data[$key] = $value;
            }
        }

        Validator::assert($data, [
            'page' => 'int|min:1',
            'perPage' => 'int|min:1|max:' . self::MAX_PER_PAGE,
        ]);

        return new self(
            isset($data['page']) ? (int) $data['page'] : self::DEFAULT_PAGE,
            isset($data['perPage']) ? (int) $data['perPage'] : self::DEFAULT_PER_PAGE,
        );
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromQuery($request->queryParams());
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @return array{page: int, perPage: int, total: int}
     */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $total,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{items: list<array<string, mixed>>, page: int, perPage: int, total: int}
     */
    public function envelope(array $items, int $total): array
    {
        return ['items' => $items] + $this->meta($total);
    }
}
