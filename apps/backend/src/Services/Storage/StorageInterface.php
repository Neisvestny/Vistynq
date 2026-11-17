<?php

declare(strict_types=1);

namespace Vistynq\Services\Storage;

/**
 * Абстракция файлового хранилища (docs/ROADMAP.md R17).
 *
 * Содержимое передаётся путём к файлу, а не строкой: лимит audio — 25 МБ,
 * держать его в памяти незачем. Ключи генерирует доменный сервис
 * (canvases/... и avatars/...), адаптер лишь проверяет их на безопасность.
 */
interface StorageInterface
{
    public function putFile(string $key, string $sourcePath, string $contentType): void;

    public function delete(string $key): void;

    /**
     * Best-effort удаление пачки объектов (после коммита БД).
     *
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): void;

    /** Presigned (S3) или статический (LocalAdapter) GET URL. */
    public function url(string $key): string;

    /** TTL ссылки в секундах — для поля expiresIn в API. */
    public function ttl(): int;

    public function exists(string $key): bool;
}
