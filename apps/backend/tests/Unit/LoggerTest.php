<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Support\Logger;
use Vistynq\Support\RequestContext;

final class LoggerTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['LOG_LEVEL', 'APP_ENV'] as $key) {
            $value = getenv($key);
            $this->savedEnv[$key] = $value === false ? null : $value;
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public function testWritesJsonLineWithRequestIdAndContext(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'logger-');
        $context = new RequestContext();
        $context->setRequestId('req-abc');
        $logger = new Logger(new Config(), $context, $logFile);

        try {
            $logger->info('hello', ['canvasId' => 'c1']);
            $entry = $this->readSingleEntry($logFile);

            $this->assertSame('info', $entry['level']);
            $this->assertSame('hello', $entry['message']);
            $this->assertSame('req-abc', $entry['requestId']);
            $this->assertSame('c1', $entry['context']['canvasId']);
            $this->assertArrayHasKey('time', $entry);
        } finally {
            @unlink($logFile);
        }
    }

    public function testRedactsSensitiveKeysAndBearerTokens(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'logger-');
        $logger = new Logger(new Config(), new RequestContext(), $logFile);

        try {
            $logger->warning('auth failed for Bearer secrettoken123', [
                'password' => 'p@ss',
                'nested' => ['refreshToken' => 'rtok', 'ok' => 'visible'],
                'authorization' => 'Bearer abc.def.ghi',
            ]);
            $line = (string) file_get_contents($logFile);
            $entry = json_decode(trim($line), true);

            $this->assertIsArray($entry);
            $this->assertStringNotContainsString('secrettoken123', $line);
            $this->assertStringNotContainsString('p@ss', $line);
            $this->assertStringNotContainsString('rtok', $line);
            $this->assertStringNotContainsString('abc.def.ghi', $line);
            $this->assertSame('[REDACTED]', $entry['context']['password']);
            $this->assertSame('[REDACTED]', $entry['context']['nested']['refreshToken']);
            $this->assertSame('visible', $entry['context']['nested']['ok']);
            $this->assertSame('[REDACTED]', $entry['context']['authorization']);
            $this->assertStringContainsString('Bearer [REDACTED]', (string) $entry['message']);
        } finally {
            @unlink($logFile);
        }
    }

    public function testRespectsLogLevel(): void
    {
        putenv('LOG_LEVEL=error');
        $_ENV['LOG_LEVEL'] = 'error';
        $logFile = tempnam(sys_get_temp_dir(), 'logger-');
        $logger = new Logger(new Config(), new RequestContext(), $logFile);

        try {
            $logger->info('skip');
            $logger->warning('skip');
            $this->assertSame('', (string) file_get_contents($logFile));

            $logger->error('keep');
            $entry = $this->readSingleEntry($logFile);
            $this->assertSame('error', $entry['level']);
            $this->assertSame('keep', $entry['message']);
        } finally {
            @unlink($logFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readSingleEntry(string $logFile): array
    {
        $line = trim((string) file_get_contents($logFile));
        $entry = json_decode($line, true);
        $this->assertIsArray($entry, 'ожидалась JSON-строка лога: ' . $line);

        return $entry;
    }
}
