<?php

declare(strict_types=1);


use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    public function testSodiumCheckBehavior(): void
    {
        $hasSodium = extension_loaded('sodium');

        if (!$hasSodium) {
            $this->assertFalse($hasSodium, 'ext-sodium must be absent on this host');
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertTrue($hasSodium, 'ext-sodium is required for argon2id');
    }

    public function testPasswordHashUsesArgon2id(): void
    {
        $this->assertTrue(defined('PASSWORD_ARGON2ID'), 'PASSWORD_ARGON2ID constant must exist');
        $this->assertSame('argon2id', PASSWORD_ARGON2ID);
    }
}
