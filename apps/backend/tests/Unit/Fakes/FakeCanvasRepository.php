<?php

declare(strict_types=1);

use Vistynq\Repositories\CanvasRepositoryInterface;

/**
 * In-memory fake for AccessService unit tests (roadmap R15/R17): no PDO,
 * rows are plain arrays seeded by the test.
 */
final class FakeCanvasRepository implements CanvasRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $canvases = [];

    /** @var array<string, string> "canvasId:user_id" => role */
    private array $access = [];

    /**
     * @param array<string,mixed> $canvas
     */
    public function add(array $canvas): void
    {
        $this->canvases[(string) $canvas['id']] = $canvas;
    }

    public function grant(string $canvasId, string $userId, string $role): void
    {
        $this->access[$canvasId . ':' . $userId] = $role;
    }

    public function findById(string $id): ?array
    {
        return $this->canvases[$id] ?? null;
    }

    public function findForUser(string $userId): array
    {
        $rows = [];
        foreach ($this->canvases as $canvas) {
            $role = $canvas['owner_id'] === $userId
                ? 'owner'
                : ($this->access[(string) $canvas['id'] . ':' . $userId] ?? null);
            if ($role !== null) {
                $rows[] = $canvas + ['my_role' => $role];
            }
        }

        return $rows;
    }

    public function create(string $ownerId, string $title): array
    {
        $row = [
            'id' => $this->uuid(),
            'owner_id' => $ownerId,
            'title' => $title,
            'access_level' => 'private',
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->canvases[$row['id']] = $row;

        return $row;
    }

    public function update(string $id, array $fields): void
    {
        if (isset($this->canvases[$id]) && array_key_exists('title', $fields)) {
            $this->canvases[$id]['title'] = $fields['title'];
        }
    }

    public function delete(string $id): void
    {
        unset($this->canvases[$id]);
    }

    public function findCanvasAccess(string $canvasId, string $userId): ?string
    {
        return $this->access[$canvasId . ':' . $userId] ?? null;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
