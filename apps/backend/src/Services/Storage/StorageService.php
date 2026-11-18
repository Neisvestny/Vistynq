<?php

declare(strict_types=1);

namespace Vistynq\Services\Storage;

use Throwable;
use Vistynq\Support\Logger;

/**
 * Доменная обёртка над StorageInterface (docs/ROADMAP.md R17).
 *
 * Удаление объектов вызывается только после коммита БД: если S3 недоступен,
 * строка БД уже удалена, поэтому ошибка уходит в лог, а не превращается в 500.
 */
final class StorageService
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly Logger $logger,
    ) {
    }

    public function put(string $key, string $sourcePath, string $mime): void
    {
        $this->storage->putFile($key, $sourcePath, $mime);
    }

    /**
     * @return array{url: string, expiresIn: int}
     */
    public function url(string $key): array
    {
        return [
            'url' => $this->storage->url($key),
            'expiresIn' => $this->storage->ttl(),
        ];
    }

    /**
     * @param list<string> $keys
     */
    public function deleteQuietly(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
        if ($keys === []) {
            return;
        }

        try {
            $this->storage->deleteMany($keys);

            return;
        } catch (Throwable $e) {
            $this->logger->error('storage batch delete failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'keys' => $keys,
            ]);
        }

        // Батч мог упасть целиком или частично — пробуем по одному, чтобы
        // потерять как можно меньше объектов.
        foreach ($keys as $key) {
            try {
                $this->storage->delete($key);
            } catch (Throwable $e) {
                $this->logger->error('storage delete failed', [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }
    }

    public function exists(string $key): bool
    {
        return $this->storage->exists($key);
    }
}
