<?php

declare(strict_types=1);

use Vistynq\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = Kernel::boot(dirname(__DIR__));
exit($kernel->run());
