<?php

declare(strict_types=1);

namespace Vistynq\Support;

/**
 * Declarative field validator.
 *
 * Usage:
 *   $errors = Validator::validate($data, [
 *       'email'    => 'required|email',
 *       'password' => 'required|string|minLength:8',
 *       'role'     => 'required|in:owner,editor,viewer',
 *   ]);
 *   if ($errors !== []) { throw ApiException::validation($errors); }
 *
 * Rules: required, string, int, numeric, bool, array, email, uuid,
 * in:a,b,c, min:n, max:n, minLength:n, maxLength:n. Fields absent from $data
 * are validated only when "required" is present.
 */
final class Validator
{
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const EMAIL_PATTERN = '/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/';

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules field => rule string ("a|b:c")
     *
     * @return array<string, list<string>> field => [messages]; empty array = valid
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $fieldErrors = self::check($value, $ruleString);
            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * Throws ApiException::validation() when the data does not match the rules.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     */
    public static function assert(array $data, array $rules, string $message = 'Проверьте поля формы'): void
    {
        $errors = self::validate($data, $rules);
        if ($errors !== []) {
            throw ApiException::validation($errors, $message);
        }
    }

    /**
     * @return list<string>
     */
    private static function check(mixed $value, string $ruleString): array
    {
        $errors = [];
        $rules = explode('|', $ruleString);
        $required = in_array('required', $rules, true);
        $present = $value !== null && $value !== '';

        if (!$present) {
            if ($required) {
                $errors[] = 'обязательное поле';
            }

            return $errors;
        }

        foreach ($rules as $rule) {
            $name = $rule;
            $arg = null;
            $colonPos = strpos($rule, ':');
            if ($colonPos !== false) {
                $name = substr($rule, 0, $colonPos);
                $arg = substr($rule, $colonPos + 1);
            }

            $error = self::applyRule($name, $arg, $value);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private static function applyRule(string $name, ?string $arg, mixed $value): ?string
    {
        switch ($name) {
            case 'required':
                return null;

            case 'string':
                return is_string($value) ? null : 'должно быть строкой';

            case 'int':
                if (is_int($value)) {
                    return null;
                }

                return is_string($value) && preg_match('/^-?\d+$/', $value) === 1
                    ? null
                    : 'должно быть целым числом';

            case 'numeric':
                // is_finite: NAN/INF проходят is_numeric, но не являются координатой
                return is_numeric($value) && is_finite((float) $value)
                    ? null
                    : 'должно быть числом';

            case 'bool':
                // строго булево: строка "false" не должна превращаться в true
                return is_bool($value) ? null : 'должно быть булевым значением';

            case 'array':
                return is_array($value) ? null : 'должно быть массивом';

            case 'email':
                return is_string($value) && preg_match(self::EMAIL_PATTERN, $value) === 1
                    ? null
                    : 'некорректный email';

            case 'uuid':
                return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1
                    ? null
                    : 'некорректный UUID';

            case 'in':
                $allowed = $arg === null ? [] : explode(',', $arg);
                // Приведение массива/объекта к строке — PHP warning (в prod это
                // 500). Такие значения просто не входят в список допустимых.
                if (!is_scalar($value)) {
                    return 'допустимые значения: ' . implode(', ', $allowed);
                }

                return in_array((string) $value, $allowed, true)
                    ? null
                    : 'допустимые значения: ' . implode(', ', $allowed);

            case 'min':
                return self::compareNumeric($value, $arg, fn (float $v, float $a): bool => $v >= $a, 'не меньше');

            case 'max':
                return self::compareNumeric($value, $arg, fn (float $v, float $a): bool => $v <= $a, 'не больше');

            case 'minLength':
                return self::compareLength($value, $arg, fn (int $l, int $a): bool => $l >= $a, 'не короче');

            case 'maxLength':
                return self::compareLength($value, $arg, fn (int $l, int $a): bool => $l <= $a, 'не длиннее');

            default:
                return 'неизвестное правило валидации: ' . $name;
        }
    }

    private static function compareNumeric(mixed $value, ?string $arg, callable $ok, string $phrase): ?string
    {
        if (!is_numeric($value) || $arg === null || !is_numeric($arg)) {
            return 'должно быть числом';
        }

        return $ok((float) $value, (float) $arg) ? null : sprintf('%s %s', $phrase, $arg);
    }

    private static function compareLength(mixed $value, ?string $arg, callable $ok, string $phrase): ?string
    {
        if (!is_string($value) || $arg === null || !ctype_digit($arg)) {
            return 'должно быть строкой';
        }

        return $ok(mb_strlen($value), (int) $arg) ? null : sprintf('%s %s симв.', $phrase, $arg);
    }
}
