<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Config\EnvLoader;
use Vistynq\Repositories\RefreshTokenRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Services\JwtService;
use Vistynq\Services\RefreshTokenService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;

final class RefreshTokenRotationTest extends TestCase
{
    private Config $config;
    private Database $db;
    private RefreshTokenRepository $refreshTokens;
    private UserRepository $users;
    private RefreshTokenService $service;
    private string $userId;
    private string $familyId;
    private string $rawToken;

    /** @var list<string> */
    private array $createdFamilyIds = [];

    protected function setUp(): void
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_pgsql extension is not available');
        }

        EnvLoader::load(__DIR__ . '/../../.env');

        $this->config = new Config();
        $this->db = new Database($this->config);
        $this->refreshTokens = new RefreshTokenRepository($this->db);
        $this->users = new UserRepository($this->db);
        $jwt = new JwtService($this->config);
        $this->service = new RefreshTokenService($this->config, $this->refreshTokens, $this->users, $jwt, $this->db);

        $email = sprintf('test-bug03-%s@example.local', bin2hex(random_bytes(4)));
        $passwordHash = password_hash('test-pass123', PASSWORD_DEFAULT);
        $user = $this->users->insert($email, $passwordHash, 'Bug03 Test User');
        $this->userId = $user->id;

        $this->familyId = $this->uuid();
        $this->rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $this->rawToken);
        $this->refreshTokens->create($this->userId, $this->familyId, $hash, $this->config->refreshTtl());
        $this->createdFamilyIds[] = $this->familyId;
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->createdFamilyIds as $familyId) {
            $pdo->prepare('DELETE FROM refresh_tokens WHERE family_id = :family_id')
                ->execute(['family_id' => $familyId]);
        }
        $pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => 'test-bug03-%']);
        $this->createdFamilyIds = [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function countActiveTokensInFamily(string $familyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT count(*) FROM refresh_tokens WHERE family_id = :family_id AND revoked_at IS NULL AND replaced_by IS NULL'
        );
        $stmt->execute(['family_id' => $familyId]);

        return (int) $stmt->fetchColumn();
    }

    private function countTokensInFamily(string $familyId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT count(*) FROM refresh_tokens WHERE family_id = :family_id');
        $stmt->execute(['family_id' => $familyId]);

        return (int) $stmt->fetchColumn();
    }

    private function tokenRow(string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, family_id, token_hash, expires_at, revoked_at, replaced_by
             FROM refresh_tokens WHERE token_hash = :hash'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function testSequentialRotationSucceeds(): void
    {
        $result = $this->service->rotate($this->rawToken);

        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('accessToken', $result);
        $this->assertArrayHasKey('cookie', $result);
        $this->assertSame($this->userId, $result['user']->id);

        $oldRow = $this->tokenRow(hash('sha256', $this->rawToken));
        $this->assertNotNull($oldRow);
        $this->assertNotNull($oldRow['revoked_at']);
        $this->assertNotNull($oldRow['replaced_by']);

        $this->assertSame(1, $this->countActiveTokensInFamily($this->familyId));
        $this->assertSame(2, $this->countTokensInFamily($this->familyId));

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->service->rotate($this->rawToken);

        $this->assertSame(0, $this->countActiveTokensInFamily($this->familyId));
    }

    public function testConcurrentRotationSameToken(): void
    {
        $rawToken = $this->rawToken;
        $worker = __DIR__ . '/RefreshTokenRotationWorker.php';
        $resultFile1 = sys_get_temp_dir() . '/bug03-result-' . uniqid('', true) . '.json';
        $resultFile2 = sys_get_temp_dir() . '/bug03-result-' . uniqid('', true) . '.json';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process1 = proc_open('php -d extension=pdo_pgsql ' . $worker . ' ' . escapeshellarg($rawToken) . ' ' . escapeshellarg($resultFile1), $descriptors, $pipes1);
        $this->assertNotFalse($process1, 'proc_open failed for worker 1');

        $process2 = proc_open('php -d extension=pdo_pgsql ' . $worker . ' ' . escapeshellarg($rawToken) . ' ' . escapeshellarg($resultFile2), $descriptors, $pipes2);
        $this->assertNotFalse($process2, 'proc_open failed for worker 2');

        foreach ($pipes1 as $pipe) {
            fclose($pipe);
        }
        foreach ($pipes2 as $pipe) {
            fclose($pipe);
        }

        $status1 = proc_close($process1);
        $status2 = proc_close($process2);

        $result1 = $this->readResultFile($resultFile1);
        $result2 = $this->readResultFile($resultFile2);

        @unlink($resultFile1);
        @unlink($resultFile2);

        $results = [$result1, $result2];
        $successes = array_filter($results, fn (array $r): bool => $r['success'] === true);
        $failures = array_filter($results, fn (array $r): bool => $r['success'] === false && ($r['code'] ?? null) === 401);

        $this->assertCount(1, $successes, 'Exactly one concurrent rotation should succeed');
        $this->assertCount(1, $failures, 'Exactly one concurrent rotation should fail with 401');

        // Reuse detection revokes the entire family, including the winner's new token.
        // After both concurrent requests complete, no active tokens remain.
        $this->assertSame(0, $this->countActiveTokensInFamily($this->familyId));
        $this->assertSame(2, $this->countTokensInFamily($this->familyId));
    }

    public function testReuseOfRevokedTokenRejected(): void
    {
        $this->service->rotate($this->rawToken);
        $this->assertSame(1, $this->countActiveTokensInFamily($this->familyId));

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->service->rotate($this->rawToken);

        $this->assertSame(0, $this->countActiveTokensInFamily($this->familyId));
    }

    public function testTransactionRollbackOnError(): void
    {
        $this->db->pdo()->prepare('DELETE FROM users WHERE id = :id')
            ->execute(['id' => $this->userId]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->service->rotate($this->rawToken);

        $row = $this->tokenRow(hash('sha256', $this->rawToken));
        $this->assertNotNull($row);
        $this->assertNull($row['revoked_at']);
        $this->assertNull($row['replaced_by']);

        $this->assertSame(1, $this->countTokensInFamily($this->familyId));
    }

    public function testTokenExpiryRejected(): void
    {
        $hash = hash('sha256', $this->rawToken);
        $expiredAt = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:sP');
        $this->db->pdo()->prepare(
            'UPDATE refresh_tokens SET expires_at = :expires_at WHERE token_hash = :hash'
        )->execute(['expires_at' => $expiredAt, 'hash' => $hash]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->service->rotate($this->rawToken);

        $this->assertSame(0, $this->countActiveTokensInFamily($this->familyId));
    }

    /**
     * @return array{success: bool, code?: int, code_name?: string, message?: string}
     */
    private function readResultFile(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return ['success' => false, 'code' => null, 'message' => 'result file unreadable'];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : ['success' => false, 'code' => null, 'message' => 'invalid result'];
    }
}
