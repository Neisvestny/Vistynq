<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PDOException;
use Vistynq\Repositories\DirectLinkRepositoryInterface;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

/**
 * Прямые (ручные) ссылки between заметками (docs/api.md §Ссылки, R15).
 *
 * Права: мутации — ≥editor канваса from-заметки (requireCanvasEdit) плюс
 * верифицированный email. Ссылка не имеет собственных прав — роль берётся
 * от канваса её источника,403/404 решает AccessService.
 *
 * Конфликты → 409: from == to, разные канвасы, дубль (предпроверка +
 * поимка 23505 при гонке — по образцу TagService).
 */
final class LinkService
{
    public function __construct(
        private readonly DirectLinkRepositoryInterface $directLinks,
        private readonly NoteRepositoryInterface $notes,
        private readonly AccessService $access,
    ) {
    }

    /**
     * POST /links/direct: {fromNoteId, toNoteId} → 201 DirectLink.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> {id, fromNoteId, toNoteId}
     */
    public function create(string $userId, array $data): array
    {
        $this->access->assertVerified($userId);

        Validator::assert($data, [
            'fromNoteId' => 'required|uuid',
            'toNoteId' => 'required|uuid',
        ]);
        $fromId = (string) $data['fromNoteId'];
        $toId = (string) $data['toNoteId'];

        if ($fromId === $toId) {
            throw ApiException::conflict('Ссылка не может вести в саму себя', [
                'toNoteId' => ['from и to должны различаться'],
            ]);
        }

        // Права — по канвасу from-заметки; недоступная/несуществующая → 404.
        $from = $this->notes->findById($fromId);
        if ($from === null) {
            throw ApiException::notFound();
        }
        $canvasId = (string) $from['canvas_id'];
        $this->access->requireCanvasEdit($canvasId, $userId);

        $to = $this->notes->findById($toId);
        if ($to === null) {
            throw ApiException::notFound();
        }
        if ($to['canvas_id'] !== $canvasId) {
            throw ApiException::conflict('Ссылки между канвасами не поддерживаются', [
                'toNoteId' => ['заметка принадлежит другому канвасу'],
            ]);
        }

        if ($this->directLinks->findByEndpoints($fromId, $toId) !== null) {
            throw ApiException::conflict('Такая ссылка уже существует');
        }

        try {
            $row = $this->directLinks->insert($fromId, $toId, $userId);
        } catch (PDOException $e) {
            // Гонка: UNIQUE (from_note_id, to_note_id)
            if ((string) $e->getCode() === '23505') {
                throw ApiException::conflict('Такая ссылка уже существует');
            }

            throw $e;
        }

        return self::toArray($row);
    }

    /**
     * DELETE /links/direct/{id} → 204.
     */
    public function delete(string $userId, string $linkId): void
    {
        $this->access->assertVerified($userId);

        $link = $this->directLinks->findById($linkId);
        if ($link === null) {
            throw ApiException::notFound();
        }

        $this->requireLinkCanvasEdit($link, $userId);
        $this->directLinks->delete($linkId);
    }

    /**
     * POST /links/direct/{id}/reverse → 200 DirectLink (направление назад).
     *
     * @return array<string, mixed> {id, fromNoteId, toNoteId}
     */
    public function reverse(string $userId, string $linkId): array
    {
        $this->access->assertVerified($userId);

        $link = $this->directLinks->findById($linkId);
        if ($link === null) {
            throw ApiException::notFound();
        }

        $this->requireLinkCanvasEdit($link, $userId);

        try {
            $row = $this->directLinks->swap($linkId);
        } catch (PDOException $e) {
            // Обратная ссылка уже существует — UNIQUE (from_note_id, to_note_id)
            if ((string) $e->getCode() === '23505') {
                throw ApiException::conflict('Обратная ссылка уже существует');
            }

            throw $e;
        }

        return self::toArray($row);
    }

    /**
     * Канвас ссылки — канвас её from-заметки; 404, если она исчезла
     * (каскад удалил бы и ссылку, но будем строги).
     *
     * @param array<string, mixed> $link
     */
    private function requireLinkCanvasEdit(array $link, string $userId): void
    {
        $from = $this->notes->findById((string) $link['from_note_id']);
        if ($from === null) {
            throw ApiException::notFound();
        }

        $this->access->requireCanvasEdit((string) $from['canvas_id'], $userId);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed> DirectLink (docs/openapi.yaml)
     */
    private static function toArray(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'fromNoteId' => (string) $row['from_note_id'],
            'toNoteId' => (string) $row['to_note_id'],
        ];
    }
}
