<?php

declare(strict_types=1);

namespace Vistynq\Models\Enums;

/**
 * notes.importance, CHECK 1..5, default 3 (docs/data-model.md).
 */
enum Importance: int
{
    case Lowest = 1;
    case Low = 2;
    case Normal = 3;
    case High = 4;
    case Critical = 5;
}
