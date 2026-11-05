<?php

declare(strict_types=1);

use Vistynq\Controllers\AuthController;
use Vistynq\Http\Request;

/**
 * R15 · интеграция auth на test-postgres: register → login → refresh → logout.
 *
 * Контроллеры вызываются напрямую (паттерн остальных интеграционных тестов),
 * контракт проверяется на статусах/телах Response и Set-Cookie.
 *
 * Запуск (нужен pdo_pgsql + test-postgres из docker-compose):
 *   DB_HOST=127.0.0.1 DB_PORT=5433 DB_NAME=vistynq_test vendor/bin/phpunit --testsuite integration
 */
final class AuthFlowTest extends IntegrationTestCase
{
    private AuthController $auth;
    private string $email;
    private string $password = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = $this->container->get(AuthController::class);
        $this->email = sprintf('r15-auth-%s@example.local', bin2hex(random_bytes(6)));
    }

    // --- Позитивный поток ---

    public function testRegisterLoginRefreshLogoutFlow(): void
    {
        // 1. register → 201
        $register = $this->auth->register($this->jsonRequest([
            'email' => $this->email,
            'password' => $this->password,
            'displayName' => 'R15 Test',
        ]));
        $this->assertSame(201, $register->status());
        $this->assertSame(['ok' => true], $this->json($register));

        $userId = $this->userIdByEmail($this->email);
        $this->assertNotNull($userId, 'юзер должен быть создан в БД');
        $this->trackUserId($userId);

        // 2. login → 200: accessToken + refresh-кука
        $login = $this->auth->login($this->jsonRequest([
            'email' => $this->email,
            'password' => $this->password,
        ]));
        $this->assertSame(200, $login->status());

        $loginBody = $this->json($login);
        $this->assertSame($userId, $loginBody['user']['id']);
        $this->assertNotSame('', $loginBody['accessToken']);
        $this->assertSame($this->config->accessTtl(), $loginBody['accessTokenExpiresIn']);

        // access-JWT реально верифицируется тем же секретом
        $claims = $this->jwt->verify($loginBody['accessToken']);
        $this->assertSame($userId, $claims['sub']);

        $cookieHeader = $login->headers()['Set-Cookie'] ?? null;
        $this->assertIsString($cookieHeader, 'login обязан ставить refresh-куку');
        $this->assertStringContainsString('vistynq_refresh=', $cookieHeader);
        $this->assertStringContainsString('HttpOnly', $cookieHeader);
        $this->assertStringContainsString('Path=/api/v1/auth/refresh', $cookieHeader);
        $this->assertStringContainsString('SameSite=Lax', $cookieHeader);

        $rawCookie = $this->refreshCookieValue($cookieHeader);
        $this->assertNotSame('', $rawCookie);

        // 3. refresh → 200: новая кука (ротация), старый токен отзывается
        $refresh = $this->auth->refresh($this->cookieRequest($rawCookie));
        $this->assertSame(200, $refresh->status());

        $refreshBody = $this->json($refresh);
        $this->assertSame($userId, $refreshBody['user']['id']);
        $this->assertNotSame('', $refreshBody['accessToken']);

        $rotatedCookie = $refresh->headers()['Set-Cookie'] ?? null;
        $this->assertIsString($rotatedCookie, 'refresh обязан ротировать куку');
        $newRaw = $this->refreshCookieValue($rotatedCookie);
        $this->assertNotSame('', $newRaw);
        $this->assertNotSame($rawCookie, $newRaw, 'refresh должен выдавать новый токен');

        // повторный refresh старым токеном = reuse → 401 (R10)
        $reuse = $this->assertApiStatus(401, fn (): mixed => $this->auth->refresh($this->cookieRequest($rawCookie)));

        // 4. logout с актуальной кукой → 200 + очистка куки
        $logout = $this->auth->logout($this->cookieRequest($newRaw));
        $this->assertSame(200, $logout->status());
        $this->assertSame(['ok' => true], $this->json($logout));

        $clearCookie = $logout->headers()['Set-Cookie'] ?? null;
        $this->assertIsString($clearCookie, 'logout обязан очистить куку');
        $this->assertStringContainsString('vistynq_refresh=;', $clearCookie);
        $this->assertStringContainsString('Max-Age=0', $clearCookie);

        // после logout refresh невозможен → 401
        $this->assertApiStatus(
            401,
            fn (): mixed => $this->auth->refresh($this->cookieRequest($newRaw)),
            'refresh после logout',
        );
    }

    public function testMeReturnsUserWithValidAccessToken(): void
    {
        $this->registerUser();
        $token = $this->loginAndGetToken();

        // В боевом пайплайне authUserId расставляет AuthMiddleware после
        // verify() токена; здесь middleware нет, поэтому передаём явно.
        $claims = $this->jwt->verify($token);
        $me = $this->auth->me($this->request(
            'GET',
            '/api/v1/auth/me',
            [],
            null,
            [],
            ['authorization' => 'Bearer ' . $token],
            $claims['sub'],
        ));

        $this->assertSame(200, $me->status());
        $body = $this->json($me);
        $this->assertSame($this->email, $body['email']);
        $this->assertArrayNotHasKey('passwordHash', $body, 'хеш пароля не должен утекать');
        $this->assertArrayNotHasKey('password_hash', $body);
    }

    // --- Негативы ---

    public function testDuplicateRegisterIs409(): void
    {
        $this->registerUser();

        $this->assertApiStatus(
            409,
            fn (): mixed => $this->auth->register($this->jsonRequest([
                'email' => $this->email,
                'password' => $this->password,
                'displayName' => 'Дубль',
            ])),
            'повторная регистрация',
        );
    }

    public function testRegisterWithInvalidPayloadIs422(): void
    {
        $this->assertApiStatus(
            422,
            fn (): mixed => $this->auth->register($this->jsonRequest([
                'email' => 'не-почта',
                'password' => 'short',
                'displayName' => '',
            ])),
            'register без валидации полей',
        );

        // ни одного юзера не создано
        $this->assertNull($this->userIdByEmail($this->email));
    }

    public function testLoginWithWrongPasswordIs401(): void
    {
        $this->registerUser();

        $this->assertApiStatus(
            401,
            fn (): mixed => $this->auth->login($this->jsonRequest([
                'email' => $this->email,
                'password' => 'wrong-password',
            ])),
            'неверный пароль',
        );
    }

    public function testLoginWithUnknownEmailIs401(): void
    {
        $this->assertApiStatus(
            401,
            fn (): mixed => $this->auth->login($this->jsonRequest([
                'email' => $this->email,
                'password' => $this->password,
            ])),
            'несуществующий email',
        );
    }

    public function testRefreshWithoutCookieIs401(): void
    {
        $this->assertApiStatus(
            401,
            fn (): mixed => $this->auth->refresh($this->cookieRequest('')),
            'refresh без куки',
        );
    }

    public function testRefreshWithGarbageCookieIs401(): void
    {
        $this->assertApiStatus(
            401,
            fn (): mixed => $this->auth->refresh($this->cookieRequest('not-a-real-token')),
            'refresh с мусорной кукой',
        );
    }

    public function testLogoutWithoutCookieStillSucceeds(): void
    {
        // logout идемпотентен: без куки — 200 + кука-очистка (R10).
        $response = $this->auth->logout($this->cookieRequest(''));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('vistynq_refresh=;', $response->headers()['Set-Cookie'] ?? '');
    }

    // --- хелперы ---

    private function registerUser(): void
    {
        $response = $this->auth->register($this->jsonRequest([
            'email' => $this->email,
            'password' => $this->password,
            'displayName' => 'R15 Test',
        ]));
        $this->assertSame(201, $response->status());

        $userId = $this->userIdByEmail($this->email);
        $this->assertNotNull($userId);
        $this->trackUserId($userId);
    }

    private function loginAndGetToken(): string
    {
        $response = $this->auth->login($this->jsonRequest([
            'email' => $this->email,
            'password' => $this->password,
        ]));
        $this->assertSame(200, $response->status());

        return (string) $this->json($response)['accessToken'];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function jsonRequest(array $json): Request
    {
        return $this->request('POST', '/api/v1/auth/register', [], $json);
    }

    private function cookieRequest(string $rawCookie): Request
    {
        $cookies = $rawCookie === '' ? [] : ['vistynq_refresh' => $rawCookie];

        return $this->request('POST', '/api/v1/auth/refresh', [], null, [], [], null, $cookies);
    }

    /**
     * Извлекает сырое значение refresh-токена из заголовка Set-Cookie.
     */
    private function refreshCookieValue(string $setCookie): string
    {
        $pair = explode(';', $setCookie, 2)[0];
        [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

        return $name === 'vistynq_refresh' ? trim($value) : '';
    }

    private function userIdByEmail(string $email): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }
}
