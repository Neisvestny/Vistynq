<?php

declare(strict_types=1);

namespace Vistynq\Support;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerResolutionException extends RuntimeException implements ContainerExceptionInterface
{
}
