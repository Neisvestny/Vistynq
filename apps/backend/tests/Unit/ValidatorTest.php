<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Support\Validator;

final class ValidatorTest extends TestCase
{
    public function testEmailValid(): void
    {
        $errors = Validator::validate(['email' => 'user@example.com'], ['email' => 'required|email']);
        $this->assertSame([], $errors);
    }

    public function testEmailInvalid(): void
    {
        $errors = Validator::validate(['email' => 'not-an-email'], ['email' => 'required|email']);
        $this->assertArrayHasKey('email', $errors);
    }

    public function testRequiredMissing(): void
    {
        $errors = Validator::validate([], ['email' => 'required|email']);
        $this->assertArrayHasKey('email', $errors);
    }

    public function testPasswordMinLength(): void
    {
        $errors = Validator::validate(['password' => 'short'], ['password' => 'required|string|minLength:8']);
        $this->assertArrayHasKey('password', $errors);
    }

    public function testPasswordMinLengthOk(): void
    {
        $errors = Validator::validate(['password' => 'password123'], ['password' => 'required|string|minLength:8']);
        $this->assertSame([], $errors);
    }

    public function testUuidValid(): void
    {
        $errors = Validator::validate(['id' => '550e8400-e29b-41d4-a716-446655440000'], ['id' => 'required|uuid']);
        $this->assertSame([], $errors);
    }

    public function testUuidInvalid(): void
    {
        $errors = Validator::validate(['id' => 'not-a-uuid'], ['id' => 'required|uuid']);
        $this->assertArrayHasKey('id', $errors);
    }

    public function testIntValid(): void
    {
        $errors = Validator::validate(['age' => 25], ['age' => 'required|int']);
        $this->assertSame([], $errors);
    }

    public function testIntStringNumericValid(): void
    {
        $errors = Validator::validate(['age' => '25'], ['age' => 'required|int']);
        $this->assertSame([], $errors);
    }

    public function testMinMaxNumeric(): void
    {
        $errors = Validator::validate(['score' => 5], ['score' => 'required|int|min:1|max:10']);
        $this->assertSame([], $errors);
    }

    public function testMinMaxNumericFail(): void
    {
        $errors = Validator::validate(['score' => 15], ['score' => 'required|int|min:1|max:10']);
        $this->assertArrayHasKey('score', $errors);
    }

    public function testUnknownRule(): void
    {
        $errors = Validator::validate(['name' => 'x'], ['name' => 'required|bogus']);
        $this->assertArrayHasKey('name', $errors);
    }

    public function testAssertThrowsOnInvalid(): void
    {
        $this->expectException(\Vistynq\Support\ApiException::class);
        Validator::assert(['email' => 'bad'], ['email' => 'required|email']);
    }

    public function testAssertPassesOnValid(): void
    {
        $this->assertNull(Validator::assert(['email' => 'a@b.com'], ['email' => 'required|email']));
    }
}
