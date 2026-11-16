<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\AuthService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

/**
 * PATCH /api/v1/profile (docs/api.md §Auth и профиль): displayName — сразу,
 * email — через pending_email + письмо на новый адрес (R10).
 */
final class ProfileController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function update(Request $request): Response
    {
        $data = $request->json();
        $userId = AuthMiddleware::userId($request);

        // Ключ присутствует, но значение пустое — ошибка, а не «нет поля»:
        // Validator пустую строку считает отсутствующей и молча пропустит.
        foreach (['displayName', 'email'] as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === '' || $data[$field] === null)) {
                throw ApiException::validation([$field => ['обязательное поле']]);
            }
        }

        Validator::assert($data, [
            'displayName' => 'string|minLength:1|maxLength:100',
            'email' => 'email',
        ]);

        $user = $this->auth->updateProfile(
            $userId,
            isset($data['displayName']) ? (string) $data['displayName'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
        );

        return Response::json($user->toArray());
    }
}
