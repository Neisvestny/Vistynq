<?php

declare(strict_types=1);

namespace Vistynq\Support;

use PDO;
use Throwable;
use Vistynq\Config\Config;

/**
 * Lazy PDO holder registered in the container as a singleton.
 * Repositories depend on this instead of raw PDO (not autowirable).
 *
 * Единственное место, где живёт PDO (roadmap R17): сервисы работают через
 * transaction()/ping(), прямой доступ к PDO у них отсутствует.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO(
            $this->config->dbDsn(),
            $this->config->dbUser(),
            $this->config->dbPass(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ],
        );
    }

    /**
     * Выполняет $fn одной транзакцией: исключение откатывает все изменения.
     * Реентерабельный: вложенная транзакция откатывается внешней.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }

        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Liveness-проба БД отдельным соединением с коротким таймаутом
     * (GET /healthz, contract-sensitive: compose/nginx healthchecks).
     *
     * @return string|null null — БД доступна, иначе код причины недоступности
     */
    public function ping(int $timeoutSeconds): ?string
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            error_log('[healthz] pdo_pgsql extension is not loaded');

            return 'pdo_pgsql_missing';
        }

        try {
            // Отдельное короткоживущее соединение, а не общий ленивый pdo():
            // проба не должна зависеть от состояния основного соединения.
            $pdo = new PDO(
                $this->config->dbDsn(),
                $this->config->dbUser(),
                $this->config->dbPass(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => $timeoutSeconds,
                ],
            );
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            error_log('[healthz] database check failed: ' . $e->getMessage());

            return 'database_unreachable';
        }

        return null;
    }
}
