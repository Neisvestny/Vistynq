<?php

declare(strict_types=1);

namespace Vistynq\Support;

/**
 * RFC 4122 UUID v4 для ключей хранилища (docs/data-model.md §attachments, R17).
 *
 * Ключ объекта не должен содержать оригинальное имя файла: uuid исключает
 * коллизии и path traversal, а расширение выводится из mime.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
