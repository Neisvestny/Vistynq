<?php

declare(strict_types=1);

namespace Vistynq\Models\Enums;

/**
 * Role of a user on a canvas or a note (canvas_access / note_access).
 */
enum AccessRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canEdit(): bool
    {
        return $this === self::Owner || $this === self::Editor;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Editor => 2,
            self::Owner => 3,
        };
    }
}
