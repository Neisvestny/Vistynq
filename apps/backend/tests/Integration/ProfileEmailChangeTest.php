<?php

declare(strict_types=1);

use Vistynq\Controllers\AuthController;
use Vistynq\Controllers\ProfileController;
use Vistynq\Http\Request;
use Vistynq\Http\Response;

/**
 * R10 · PATCH /profile и смена email через pending_email:
 * имя — сразу; email — письмо на НОВЫЙ адрес (purpose=email_change),
 * смена только после POST /auth/verify-email (docs/architecture.md §2).
 *
 * Запуск (нужен pdo_pgsql + test-postgres + Mailpit из docker-compose):
 *   DB_HOST=127.0.0.1 DB_PORT=5433 DB_NAME=vistynq_test vendor/bin/phpunit --testsuite integration
 */
final class ProfileEmailChangeTest extends IntegrationTestCase
{
    private AuthController $auth;
    private ProfileController $profile;
    private string $email;
    private string $password = 'secret-pass-123';
    private string $userId;
    private string $accessToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = $this->container->get(AuthController::class);
        $this->profile = $this->container->get(ProfileController::class);
        $this->email = sprintf('r10-profile-%s@example.local', bin2hex(random_bytes(6)));

        $this->registerVerifyLogin();
    }

    // --- displayName ---

    public function testUpdateDisplayNameChangesNameImmediately(): void
    {
        $response = $this->patchProfile(['displayName' => 'Новое Имя']);

        $this->assertSame(200, $response->status());
        $body = $this->json($response);
        $this->assertSame('Новое Имя', $body['displayName']);
        $this->assertSame($this->email, $body['email']);
        $this->assertNull($body['pendingEmail'], 'pendingEmail должен отсутствовать, пока смена не запрошена');

        // Имя видно и в /auth/me
        $me = $this->auth->me($this->authedRequest('GET', '/api/v1/auth/me'));
        $this->assertSame('Новое Имя', $this->json($me)['displayName']);
    }

    // --- смена email ---

    public function testEmailChangeFlowViaMailpit(): void
    {
        $newEmail = sprintf('r10-new-%s@example.local', bin2hex(random_bytes(6)));

        // 1. PATCH email → 200: email старый, pendingEmail новый
        $response = $this->patchProfile(['email' => $newEmail]);
        $this->assertSame(200, $response->status());

        $body = $this->json($response);
        $this->assertSame($this->email, $body['email'], 'email не меняется до подтверждения');
        $this->assertSame($newEmail, $body['pendingEmail']);
        $this->assertTrue($body['emailVerified'], 'пользователь остаётся верифицированным по старому адресу');

        // 2. вход со старым адресом всё ещё работает (docs/data-model.md §31)
        $login = $this->auth->login($this->jsonRequest('/api/v1/auth/login', [
            'email' => $this->email,
            'password' => $this->password,
        ]));
        $this->assertSame(200, $login->status());

        // 3. письмо смены пришло на НОВЫЙ адрес, токен подтверждает смену
        $token = $this->fetchVerifyTokenFromMailpit($newEmail);
        $verify = $this->auth->verifyEmail($this->jsonRequest('/api/v1/auth/verify-email', ['token' => $token]));
        $this->assertSame(200, $verify->status());

        // 4. email обновлён, pendingEmail очищен
        $me = $this->auth->me($this->authedRequest('GET', '/api/v1/auth/me'));
        $meBody = $this->json($me);
        $this->assertSame($newEmail, $meBody['email']);
        $this->assertNull($meBody['pendingEmail']);
        $this->assertTrue($meBody['emailVerified']);

        // 5. вход со старым адресом падает, с новым — работает
        $this->assertApiStatus(401, fn (): mixed => $this->auth->login($this->jsonRequest('/api/v1/auth/login', [
            'email' => $this->email,
            'password' => $this->password,
        ])), 'login со старым email после смены');

        $loginNew = $this->auth->login($this->jsonRequest('/api/v1/auth/login', [
            'email' => $newEmail,
            'password' => $this->password,
        ]));
        $this->assertSame(200, $loginNew->status());
    }

    public function testRepeatedEmailChangeInvalidatesPreviousToken(): void
    {
        $first = sprintf('r10-first-%s@example.local', bin2hex(random_bytes(6)));
        $second = sprintf('r10-second-%s@example.local', bin2hex(random_bytes(6)));

        $this->patchProfile(['email' => $first]);
        $staleToken = $this->fetchVerifyTokenFromMailpit($first);

        // Новый PATCH перезаписывает pending_email и отзывает старый токен
        $this->patchProfile(['email' => $second]);

        $this->assertApiStatus(
            410,
            fn (): mixed => $this->auth->verifyEmail($this->jsonRequest('/api/v1/auth/verify-email', ['token' => $staleToken])),
            'старый токен смены после повторного PATCH',
        );

        // Актуальным токеном (на второй адрес) смена проходит
        $token = $this->fetchVerifyTokenFromMailpit($second);
        $verify = $this->auth->verifyEmail($this->jsonRequest('/api/v1/auth/verify-email', ['token' => $token]));
        $this->assertSame(200, $verify->status());

        $me = $this->auth->me($this->authedRequest('GET', '/api/v1/auth/me'));
        $this->assertSame($second, $this->json($me)['email']);
    }

    public function testEmailChangeConflictIs409(): void
    {
        // Чужой email с pending_email другого юзера тоже занят (partial UNIQUE)
        $otherId = $this->createUser();
        $taken = sprintf('r10-taken-%s@example.local', bin2hex(random_bytes(6)));
        $this->users->setPendingEmail($otherId, $taken);

        $e = $this->assertApiStatus(
            409,
            fn (): mixed => $this->patchProfile(['email' => $taken]),
            'email в pending_email у другого юзера',
        );
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function testEmailChangeToOwnEmailIsNoop(): void
    {
        $response = $this->patchProfile(['email' => $this->email]);

        $this->assertSame(200, $response->status());
        $this->assertNull($this->json($response)['pendingEmail'], 'смена на текущий адрес не создаёт pending');
    }

    // --- валидация / авторизация ---

    public function testInvalidPayloadIs422(): void
    {
        $this->assertApiStatus(
            422,
            fn (): mixed => $this->patchProfile(['email' => 'не-почта']),
            'невалидный email',
        );

        $this->assertApiStatus(
            422,
            fn (): mixed => $this->patchProfile(['displayName' => '']),
            'пустой displayName (ключ есть, значения нет)',
        );

        $this->assertApiStatus(
            422,
            fn (): mixed => $this->patchProfile(['displayName' => str_repeat('я', 101)]),
            'displayName длиннее 100 символов',
        );
    }

    public function testWithoutAccessTokenIs401(): void
    {
        $this->assertApiStatus(
            401,
            fn (): mixed => $this->profile->update($this->request('PATCH', '/api/v1/profile', [], ['displayName' => 'Хакер'])),
            'PATCH /profile без Bearer',
        );
    }

    public function testEmptyBodyReturnsCurrentUserNoop(): void
    {
        $response = $this->patchProfile([]);

        $this->assertSame(200, $response->status());
        $body = $this->json($response);
        $this->assertSame($this->email, $body['email']);
        $this->assertNull($body['pendingEmail']);
    }

    // --- хелперы ---

    private function registerVerifyLogin(): void
    {
        $register = $this->auth->register($this->jsonRequest('/api/v1/auth/register', [
            'email' => $this->email,
            'password' => $this->password,
            'displayName' => 'R10 Test',
        ]));
        $this->assertSame(201, $register->status());

        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $this->email]);
        $this->userId = (string) $stmt->fetchColumn();
        $this->trackUserId($this->userId);

        $verifyToken = $this->fetchVerifyTokenFromMailpit($this->email);
        $verify = $this->auth->verifyEmail($this->jsonRequest('/api/v1/auth/verify-email', ['token' => $verifyToken]));
        $this->assertSame(200, $verify->status());

        $login = $this->auth->login($this->jsonRequest('/api/v1/auth/login', [
            'email' => $this->email,
            'password' => $this->password,
        ]));
        $this->assertSame(200, $login->status());
        $this->accessToken = (string) $this->json($login)['accessToken'];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function patchProfile(array $json): Response
    {
        return $this->profile->update($this->authedRequest('PATCH', '/api/v1/profile', $json));
    }

    /**
     * @param array<string, mixed> $json
     */
    private function authedRequest(string $method, string $path, ?array $json = null): Request
    {
        // Bearer расставляет AuthMiddleware в боевом пайплайне; здесь его нет,
        // поэтому authUserId передаём явно (паттерн AuthFlowTest::testMe…).
        return $this->request($method, $path, [], $json, [], ['authorization' => 'Bearer ' . $this->accessToken], $this->userId);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function jsonRequest(string $path, array $json): Request
    {
        return $this->request('POST', $path, [], $json);
    }

}
