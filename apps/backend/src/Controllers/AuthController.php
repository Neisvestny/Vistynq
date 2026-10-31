<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Repositories\UserRepository;
use Vistynq\Services\AuthService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

/**
 * Auth endpoints (docs/api.md §Auth и профиль).
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users,
    ) {
    }

    public function register(Request $request): Response
    {
        $data = $request->json();
        Validator::assert($data, [
            'email' => 'required|email',
            'password' => 'required|string|minLength:8',
            'displayName' => 'required|string|minLength:1|maxLength:100',
        ]);

        $this->auth->register((string) $data['email'], (string) $data['password'], (string) $data['displayName']);

        return Response::json(['ok' => true], 201);
    }

    public function verifyEmail(Request $request): Response
    {
        $data = $request->json();
        Validator::assert($data, ['token' => 'required|string']);

        $this->auth->verifyEmail((string) $data['token']);

        return Response::json(['ok' => true]);
    }

    public function resendVerification(Request $request): Response
    {
        $data = $request->json();
        Validator::assert($data, ['email' => 'required|email']);

        $this->auth->resendVerification((string) $data['email']);

        return Response::json(['ok' => true], 202);
    }

    public function login(Request $request): Response
    {
        $data = $request->json();
        Validator::assert($data, [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $result = $this->auth->login((string) $data['email'], (string) $data['password']);

        return Response::json([
            'user' => $result['user']->toArray(),
            'accessToken' => $result['accessToken'],
            'accessTokenExpiresIn' => 900,
        ]);
    }

    public function me(Request $request): Response
    {
        $user = $this->users->findById(AuthMiddleware::userId($request));
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        return Response::json($user->toArray());
    }
}
