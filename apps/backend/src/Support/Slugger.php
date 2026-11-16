<?php

declare(strict_types=1);

namespace Vistynq\Support;

/**
 * Генерация публичного slug канваса (docs/ROADMAP.md R16).
 *
 * Slug не обязан быть человекочитаемым: фронтенд просто использует его в
 * ссылке /shared/canvas/{slug}. Hex-строка исключает проблемы с
 * транслитерацией кириллицы и остаётся URL-safe без дополнительного
 * кодирования. Уникальность в БД гарантирует UNIQUE(public_slug), коллизия
 * обрабатывается повтором в SharingService.
 */
final class Slugger
{
    private const BYTES = 8;

    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }
}
