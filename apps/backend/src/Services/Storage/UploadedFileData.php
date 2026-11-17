<?php

declare(strict_types=1);

namespace Vistynq\Services\Storage;

/**
 * Результат проверки загруженного файла (R17): mime определён по содержимому,
 * расширение выведено из mime, оригинальное имя оставлено только для БД.
 */
final class UploadedFileData
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $originalName,
        public readonly string $mime,
        public readonly string $extension,
        public readonly string $kind,
        public readonly int $size,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }
}
