<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Support\Uuid;

/**
 * UUID v4 — формат ключей хранилища (R17): версия/вариант по RFC 4122,
 * без зависимости от внешних библиотек.
 */
final class UuidTest extends TestCase
{
    public function testMatchesRfc4122Version4(): void
    {
        $uuid = Uuid::v4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
        $this->assertSame(36, strlen($uuid));
    }

    public function testValuesAreUnique(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $seen[Uuid::v4()] = true;
        }

        $this->assertCount(200, $seen);
    }
}
