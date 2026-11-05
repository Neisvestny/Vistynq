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

    public function testNumericAcceptsIntFloatAndNumericString(): void
    {
        $rules = ['x' => 'numeric'];
        $this->assertSame([], Validator::validate(['x' => 10], $rules));
        $this->assertSame([], Validator::validate(['x' => -3.5], $rules));
        $this->assertSame([], Validator::validate(['x' => '120.5'], $rules));
    }

    public function testNumericRejectsNonNumericAndNonFinite(): void
    {
        $rules = ['x' => 'numeric'];
        $this->assertArrayHasKey('x', Validator::validate(['x' => 'abc'], $rules));
        $this->assertArrayHasKey('x', Validator::validate(['x' => []], $rules));
        $this->assertArrayHasKey('x', Validator::validate(['x' => NAN], $rules));
        $this->assertArrayHasKey('x', Validator::validate(['x' => INF], $rules));
    }

    public function testBoolIsStrict(): void
    {
        $rules = ['flag' => 'bool'];
        $this->assertSame([], Validator::validate(['flag' => false], $rules));
        $this->assertSame([], Validator::validate(['flag' => true], $rules));
        // строка "false" не должна превращаться в true
        $this->assertArrayHasKey('flag', Validator::validate(['flag' => 'false'], $rules));
        $this->assertArrayHasKey('flag', Validator::validate(['flag' => 0], $rules));
    }

    public function testArrayRule(): void
    {
        $rules = ['tagIds' => 'array'];
        $this->assertSame([], Validator::validate(['tagIds' => []], $rules));
        $this->assertSame([], Validator::validate(['tagIds' => ['a']], $rules));
        $this->assertArrayHasKey('tagIds', Validator::validate(['tagIds' => 'nope'], $rules));
        $this->assertArrayHasKey('tagIds', Validator::validate(['tagIds' => 1], $rules));
    }

    public function testBoolFalsePassesRequired(): void
    {
        $errors = Validator::validate(['isRelevant' => false], ['isRelevant' => 'required|bool']);
        $this->assertSame([], $errors);
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

    public function testAssertThrows422WithFieldDetails(): void
    {
        try {
            Validator::assert(['email' => 'bad'], ['email' => 'required|email'], 'Проверьте форму');
            $this->fail('ожидалась ApiException');
        } catch (\Vistynq\Support\ApiException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame('VALIDATION_FAILED', $e->errorCode());
            $this->assertSame('Проверьте форму', $e->getMessage());
            $details = $e->toArray()['error']['details'];
            $this->assertArrayHasKey('email', $details);
            $this->assertContains('некорректный email', $details['email']);
        }
    }

    public function testInRule(): void
    {
        $rules = ['status' => 'required|in:active,archived'];

        $this->assertSame([], Validator::validate(['status' => 'active'], $rules));
        $this->assertSame([], Validator::validate(['status' => 'archived'], $rules));

        $errors = Validator::validate(['status' => 'deleted'], $rules);
        $this->assertArrayHasKey('status', $errors);
        $this->assertStringContainsString('active, archived', $errors['status'][0]);
    }

    public function testInRuleRejectsNonStringableArrayValue(): void
    {
        // Массив нельзя привести к строке стабильно — считаем нарушением.
        $errors = Validator::validate(['status' => ['active']], ['status' => 'in:active']);

        $this->assertArrayHasKey('status', $errors);
    }

    public function testMaxLength(): void
    {
        $rules = ['title' => 'string|maxLength:5'];

        $this->assertSame([], Validator::validate(['title' => 'абвгд'], $rules));
        $this->assertArrayHasKey('title', Validator::validate(['title' => 'абвгде'], $rules));
        // длина в символах, а не в байтах (UTF-8)
        $this->assertArrayHasKey('title', Validator::validate(['title' => 'abcdefghij'], $rules));
    }

    public function testMinMaxLengthRejectNonString(): void
    {
        $this->assertArrayHasKey('t', Validator::validate(['t' => 123], ['t' => 'minLength:1']));
        $this->assertArrayHasKey('t', Validator::validate(['t' => 123], ['t' => 'maxLength:10']));
    }

    public function testEmptyStringTreatedAsAbsentForOptionalField(): void
    {
        // Пустая строка = поле не заполнено: без required — без ошибок.
        $this->assertSame([], Validator::validate(['note' => ''], ['note' => 'string|maxLength:5']));
        // с required — ошибка «обязательное поле», а не «должно быть строкой».
        $errors = Validator::validate(['note' => ''], ['note' => 'required|string']);
        $this->assertSame(['обязательное поле'], $errors['note']);
    }

    public function testOptionalFieldMissingIsFine(): void
    {
        $this->assertSame([], Validator::validate([], ['optional' => 'email']));
    }

    public function testSeveralRulesOnOneFieldCollectAllErrors(): void
    {
        $errors = Validator::validate(['age' => 'abc'], ['age' => 'required|int|min:1']);

        $this->assertArrayHasKey('age', $errors);
        $this->assertContains('должно быть целым числом', $errors['age']);
    }
}
