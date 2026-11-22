<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Config\Config;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Repositories\UserRepositoryInterface;
use Vistynq\Services\AuthService;
use Vistynq\Services\UserPresenter;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepositoryInterface $users,
        private readonly Config $config,
        private readonly UserPresenter $presenter,
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

        // Новый аккаунт не должен наследовать чужую сессию: отзываем
        // refresh-семью из куки и очищаем куку в том же ответе.
        $clearCookie = $this->auth->logout($this->refreshCookieFrom($request));

        return Response::json(['ok' => true], 201)->withHeaders(['Set-Cookie' => $clearCookie]);
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

        // Вход в другой аккаунт завершает предыдущую сессию — иначе старая
        // кука останется валидной и restoreSession вернёт прежнего юзера.
        $this->auth->logout($this->refreshCookieFrom($request));

        $response = Response::json([
            'user' => $this->presenter->present($result['user']),
            'accessToken' => $result['accessToken'],
            'accessTokenExpiresIn' => $this->config->accessTtl(),
        ]);

        return $response->withHeaders(['Set-Cookie' => $result['cookie']]);
    }

    public function refresh(Request $request): Response
    {
        $raw = $this->refreshCookieFrom($request);
        if ($raw === '') {
            throw ApiException::unauthorized();
        }

        $result = $this->auth->refresh($raw);

        $response = Response::json([
            'user' => $this->presenter->present($result['user']),
            'accessToken' => $result['accessToken'],
            'accessTokenExpiresIn' => $this->config->accessTtl(),
        ]);

        return $response->withHeaders(['Set-Cookie' => $result['cookie']]);
    }

    public function logout(Request $request): Response
    {
        $clear = $this->auth->logout($this->refreshCookieFrom($request));

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

        return Response::json($this->presenter->present($user));
    }

    private function refreshCookieFrom(Request $request): string
    {
        $raw = $request->cookie('vistynq_refresh');

        return is_string($raw) ? $raw : '';
    }
}
