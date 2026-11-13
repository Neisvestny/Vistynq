<?php

declare(strict_types=1);

namespace Vistynq\Models\Enums;

/**
 * canvases.access_level (docs/data-model.md).
 */
enum AccessLevel: string
{
    case Private = 'private';
    case Public = 'public';
}
