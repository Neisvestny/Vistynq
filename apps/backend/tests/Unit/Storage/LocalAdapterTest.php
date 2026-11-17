<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vistynq\Services\Storage\LocalAdapter;

/**
 * LocalAdapter — dev/test fallback без MinIO (R17). Проверяем запись через
 * временный файл, защиту от path traversal и статический URL /storage/{key}.
 */
final class LocalAdapterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/vistynq-local-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function testPutFileStoresContentAndReportsExistence(): void
    {
        $adapter = $this->adapter();
        $source = $this->sourceFile('binary-content');

        $adapter->putFile('canvases/c1/notes/n1/uuid.png', $source, 'image/png');

        $this->assertTrue($adapter->exists('canvases/c1/notes/n1/uuid.png'));
        $this->assertSame(
            'binary-content',
            file_get_contents($this->basePath . '/canvases/c1/notes/n1/uuid.png'),
        );
    }

    public function testPutFileOverwritesExistingObject(): void
    {
        $adapter = $this->adapter();

        $adapter->putFile('avatars/u1/a.png', $this->sourceFile('old'), 'image/png');
        $adapter->putFile('avatars/u1/a.png', $this->sourceFile('new'), 'image/png');

        $this->assertSame('new', file_get_contents($this->basePath . '/avatars/u1/a.png'));
    }

    public function testUrlIsStaticAndSegmentEncoded(): void
    {
        $adapter = $this->adapter(appUrl: 'https://app.example.com/');

        $this->assertSame(
            'https://app.example.com/storage/avatars/u1/%D1%84%D0%B0%D0%B9%D0%BB%201.png',
            $adapter->url('avatars/u1/файл 1.png'),
        );
        $this->assertSame(1800, $adapter->ttl());
    }

    public function testDeleteIsIdempotentAndDeleteManySkipsMissing(): void
    {
        $adapter = $this->adapter();
        $adapter->putFile('a/b.png', $this->sourceFile('x'), 'image/png');
        $adapter->putFile('a/c.png', $this->sourceFile('y'), 'image/png');

        $adapter->deleteMany(['a/b.png', 'a/c.png', 'a/missing.png']);
        $adapter->delete('a/b.png');

        $this->assertFalse($adapter->exists('a/b.png'));
        $this->assertFalse($adapter->exists('a/c.png'));
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsUnsafeKeys(string $key): void
    {
        $adapter = $this->adapter();

        $this->expectException(InvalidArgumentException::class);

        $adapter->putFile($key, $this->sourceFile('x'), 'image/png');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'parent directory' => ['../secret.png'];
        yield 'nested parent' => ['avatars/../../secret.png'];
        yield 'null byte' => ["avatars/u1/a\0.png"];
    }

    private function adapter(
        string $appUrl = 'http://localhost:8000',
        int $ttl = 1800,
    ): LocalAdapter {
        return new LocalAdapter($this->basePath, $appUrl, $ttl);
    }

    private function sourceFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistynq-src-');
        $this->assertNotFalse($path);
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
