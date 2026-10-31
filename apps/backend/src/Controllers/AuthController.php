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

        $response = Response::json([
            'user' => $result['user']->toArray(),
            'accessToken' => $result['accessToken'],
            'accessTokenExpiresIn' => 900,
        ]);

        return $response->withHeaders(['Set-Cookie' => $result['cookie']]);
    }

    public function refresh(Request $request): Response
    {
        $raw = $request->cookie('vistynq_refresh');
        if (!is_string($raw) || $raw === '') {
            throw ApiException::unauthorized();
        }

        $result = $this->auth->refresh($raw);

        $response = Response::json([
            'accessToken' => $result['accessToken'],
            'accessTokenExpiresIn' => 900,
        ]);

        return $response->withHeaders(['Set-Cookie' => $result['cookie']]);
    }

    public function logout(Request $request): Response
    {
        $raw = $request->cookie('vistynq_refresh');
        $clear = $this->auth->logout(is_string($raw) ? $raw : '');

        $response = Response::json(['ok' => true]);
        return $response->withHeaders(['Set-Cookie' => $clear]);
    }

    public function forgotPassword(Request $request): Response
    {
        $data = $request->json();
        Validator::assert($data, ['email' => 'required|email']);

        $this->auth->forgotPassword((string) $data['email']);

        return Response::json(['ok' => true], 202);
    }

    public function resetPassword(Request $request): Response
    {
        $data = $request->json();
        Validator::assert($data, [
            'token' => 'required|string',
            'password' => 'required|string|minLength:8',
        ]);

        $this->auth->resetPassword((string) $data['token'], (string) $data['password']);

        return Response::json(['ok' => true]);
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
