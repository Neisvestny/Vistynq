<?php

declare(strict_types=1);

namespace Vistynq\Support;

/**
 * Контекст текущего запроса (R20): хранит requestId, чтобы Logger мог
 * добавить его в каждую строку лога. Заполняется RequestIdMiddleware.
 *
 * Singleton в контейнере: в PHP-FPM один воркер обслуживает один запрос,
 * поэтому мутируемое состояние безопасно в рамках запроса.
 */
final class RequestContext
{
    private ?string $requestId = null;

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
