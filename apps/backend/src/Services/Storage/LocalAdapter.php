<?php

declare(strict_types=1);

namespace Vistynq\Services\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * Локальный fallback для dev/test без MinIO (docs/architecture.md R17).
 *
 * Файлы лежат в apps/backend/storage (gitignored), nginx отдаёт их по
 * /storage/{key}. Ссылка статическая, без TTL: это осознанно только для
 * локального запуска, в проде используется S3Adapter.
 */
final class LocalAdapter implements StorageInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $appUrl,
        private readonly int $ttl,
    ) {
    }

    public function putFile(string $key, string $sourcePath, string $contentType): void
    {
        $target = $this->pathForKey($key);
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Не удалось создать каталог хранилища: {$directory}");
        }

        // Запись через временный файл в том же каталоге: читатель никогда не
        // увидит частично записанный объект.
        $temporary = tempnam($directory, '.upload-');
        if ($temporary === false) {
            throw new RuntimeException("Не удалось создать временный файл в {$directory}");
        }

        try {
            if (!copy($sourcePath, $temporary)) {
                throw new RuntimeException("Не удалось записать файл в хранилище: {$key}");
            }
            chmod($temporary, 0o644);

            if (!rename($temporary, $target)) {
                throw new RuntimeException("Не удалось опубликовать файл в хранилище: {$key}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function delete(string $key): void
    {
        $path = $this->pathForKey($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteMany(array $keys): void
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
    }

    public function url(string $key): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $key)));

        return rtrim($this->appUrl, '/') . '/storage/' . $encoded;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function exists(string $key): bool
    {
        return is_file($this->pathForKey($key));
    }

    private function pathForKey(string $key): string
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Недопустимый ключ хранилища');
        }

        return rtrim($this->basePath, '/') . '/' . $key;
    }
}
