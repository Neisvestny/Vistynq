<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\User;

/**
 * Публичное представление User (docs/openapi.yaml → User, R17).
 *
 * Модель не знает про storage: avatarUrl — presigned/static ссылка,
 * вычисляемая на границе HTTP. Внутренний avatarAttachmentId наружу не идёт.
 */
final class UserPresenter
{
    public function __construct(private readonly AvatarService $avatars)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        return $user->toArray($this->avatars->urlForUser($user));
    }
}
