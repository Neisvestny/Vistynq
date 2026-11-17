<?php

declare(strict_types=1);

namespace Vistynq\Services\Storage;

use Aws\S3\S3Client;
use RuntimeException;

/**
 * S3/MinIO-адаптер на aws-sdk (docs/ROADMAP.md R17).
 *
 * Два клиента: внутренний (Put/Delete/Head) и публичный (только presign).
 * SigV4 подписывает Host, поэтому presigned URL, подписанный внутренним
 * endpoint'ом minio:9000, в браузере не откроется.
 */
final class S3Adapter implements StorageInterface
{
    private const DELETE_BATCH = 1000;

    public function __construct(
        private readonly S3Client $client,
        private readonly S3Client $publicClient,
        private readonly string $bucket,
        private readonly int $ttl,
    ) {
    }

    public function putFile(string $key, string $sourcePath, string $contentType): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $sourcePath,
            'ContentType' => $contentType,
        ]);
    }

    public function delete(string $key): void
    {
        // DELETE объекта в S3 идемпотентен: отсутствие ключа не ошибка.
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }

    public function deleteMany(array $keys): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), self::DELETE_BATCH) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $objects = array_map(static fn (string $key): array => ['Key' => $key], $chunk);

            $result = $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects, 'Quiet' => true],
            ]);

            // DeleteObjects отвечает 200 даже при частичных ошибках: их нужно
            // поднять, чтобы StorageService повторил удаление по одному.
            $errors = $result['Errors'] ?? [];
            if (is_array($errors) && $errors !== []) {
                $summary = implode(', ', array_map(
                    static fn (array $error): string => (string) ($error['Key'] ?? '?') . ': ' . (string) ($error['Code'] ?? '?'),
                    $errors,
                ));
                throw new RuntimeException('S3 DeleteObjects partial failure: ' . $summary);
            }
        }
    }

    public function url(string $key): string
    {
        $command = $this->publicClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (string) $this->publicClient
            ->createPresignedRequest($command, "+{$this->ttl} seconds")
            ->getUri();
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }
}
