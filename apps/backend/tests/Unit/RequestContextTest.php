<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Support\RequestContext;

final class RequestContextTest extends TestCase
{
    public function testStoresRequestId(): void
    {
        $context = new RequestContext();

        $this->assertNull($context->requestId());

        $context->setRequestId('req-123');
        $this->assertSame('req-123', $context->requestId());

        $context->setRequestId(null);
        $this->assertNull($context->requestId());
    }
}
