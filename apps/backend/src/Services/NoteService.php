<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\Attachment;
use Vistynq\Models\Note;
use Vistynq\Repositories\AttachmentRepositoryInterface;
use Vistynq\Repositories\DeletedNoteRepositoryInterface;
use Vistynq\Repositories\DirectLinkRepositoryInterface;
use Vistynq\Repositories\IndirectLinkRepositoryInterface;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Repositories\TagRepositoryInterface;
use Vistynq\Services\Storage\StorageService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;
use Vistynq\Support\Validator;

/**
 * Жизненный цикл заметки (docs/api.md §Заметки, docs/ROADMAP.md R12).
 *
 * Права: манифест/тело/soft delete — редактор* (AccessService::requireNoteEdit),
 * позиции — ≥editor канваса (requireCanvasEdit), restore/purge — ≥editor канваса
 * либо автор (requireNoteManage). Все мутации требуют верифицированного email.
 */
final class NoteService
{
    public function __construct(
        private readonly NoteRepositoryInterface $notes,
        private readonly DeletedNoteRepositoryInterface $deletedNotes,
        private readonly TagRepositoryInterface $tags,
        private readonly IndirectLinkRepositoryInterface $indirectLinks,
        private readonly DirectLinkRepositoryInterface $directLinks,
        private readonly AccessService $access,
        private readonly LinkParserService $parser,
        private readonly Database $db,
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly StorageService $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $data {title?, x?, y?}
     *
     * @return array<string, mixed> Note (docs/openapi.yaml)
     */
    public function create(string $canvasId, string $userId, array $data): array
    {
        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit($canvasId, $userId);

        Validator::assert($data, [
            'title' => 'string|maxLength:200',
            'x' => 'numeric',
            'y' => 'numeric',
        ]);

        $title = isset($data['title']) && is_string($data['title']) ? trim($data['title']) : '';
        if ($title === '') {
            $title = 'Без названия';
        }

        $id = $this->notes->create(
            $canvasId,
            $userId,
            $title,
            self::numericField($data, 'x') ?? 0.0,
            self::numericField($data, 'y') ?? 0.0,
        );

        $row = $this->notes->findById($id);
        if ($row === null) {
            throw ApiException::notFound();
        }

        return Note::fromRow($row, [])->toArray();
    }

    /**
     * POST /canvases/{id}/notes/from-text: импорт .md/.txt (docs/api.md).
     * Порядок: права (403/404/403), затем файл (422/415/413); тело файла
     * становится bodyMd и парсится на wikilinks той же транзакцией, что и
     * запись тела, чтобы не остались ни заметка без линков, ни линки без
     * тела.
     *
     * @param array<string, mixed> $fields поля title?, x?, y? из multipart
     * @param array<string, mixed>|null $file $_FILES['file']
     *
     * @return array<string, mixed> Note (docs/openapi.yaml)
     */
    public function createFromText(string $canvasId, string $userId, array $fields, ?array $file): array
    {
        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit($canvasId, $userId);

        // 422 нет файла → 415 не .md/.txt → 413 больше 1 МБ
        $extension = self::importExtension($file);
        if (!in_array($extension, ['md', 'txt'], true)) {
            throw ApiException::make(
                415,
                'UNSUPPORTED_MEDIA_TYPE',
                'Поддерживаются только файлы .md и .txt',
            );
        }
        $bodyMd = self::importBody($file);

        Validator::assert($fields, [
            'title' => 'string|maxLength:200',
            'x' => 'numeric',
            'y' => 'numeric',
        ]);

        $title = self::importTitle($fields, (string) ($file['name'] ?? ''));
        $id = $this->notes->create(
            $canvasId,
            $userId,
            $title,
            self::numericField($fields, 'x') ?? 0.0,
            self::numericField($fields, 'y') ?? 0.0,
        );

        $this->db->transaction(function () use ($id, $canvasId, $bodyMd): void {
            $this->notes->updateBody($id, $bodyMd);
            $this->parser->parseAndStore($canvasId, $id, $bodyMd);
        });

        $row = $this->notes->findById($id);
        if ($row === null) {
            throw ApiException::notFound();
        }

        return Note::fromRow($row, [])->toArray();
    }

    /**
     * POST /canvases/{id}/notes/derive: «создать от этой заметки» — новая
     * заметка + прямой линк parent → дочерняя, одной транзакцией
     * (docs/api.md §Заметки, openapi: 201 {note, link}).
     *
     * @param array<string, mixed> $data {parentNoteId, x?, y?}
     *
     * @return array<string, mixed> {note: Note, link: DirectLink}
     */
    public function derive(string $canvasId, string $userId, array $data): array
    {
        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit($canvasId, $userId);

        Validator::assert($data, [
            'parentNoteId' => 'required|uuid',
            'x' => 'numeric',
            'y' => 'numeric',
        ]);

        $parentId = (string) $data['parentNoteId'];
        $parent = $this->notes->findById($parentId);
        if ($parent === null || $parent['canvas_id'] !== $canvasId) {
            throw ApiException::notFound();
        }

        $x = self::numericField($data, 'x') ?? 0.0;
        $y = self::numericField($data, 'y') ?? 0.0;

        // Заметка и линк — одной транзакцией: не остаётся ни дочерней
        // заметки без родителя, ни линка без заметки.
        $childId = null;
        $link = null;
        $this->db->transaction(function () use ($canvasId, $userId, $parentId, $x, $y, &$childId, &$link): void {
            $childId = $this->notes->create($canvasId, $userId, 'Без названия', $x, $y);
            $link = $this->directLinks->insert($parentId, $childId, $userId);
        });

        $row = $childId === null ? null : $this->notes->findById($childId);
        if ($row === null || $link === null) {
            throw ApiException::notFound();
        }

        return [
            'note' => Note::fromRow($row, [])->toArray(),
            'link' => [
                'id' => (string) $link['id'],
                'fromNoteId' => (string) $link['from_note_id'],
                'toNoteId' => (string) $link['to_note_id'],
            ],
        ];
    }

    /**
     * @return array<string, mixed> NoteDetail (docs/openapi.yaml)
     */
    public function get(string $noteId, ?string $userId): array
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);

        return Note::fromRow($ctx['note'], $this->tagIds($noteId))->toDetailArray(
            (string) $ctx['note']['body_md'],
            $this->access->describeNoteRole($ctx),
            $this->attachmentPayloads($noteId),
        );
    }

    /**
     * Манифест: {title?, importance?, isRelevant?, tagIds?}.
     * Обновление строки и синхронизация тегов — одной транзакцией
     * (docs/architecture.md: «пересоздание … в транзакции»).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> NoteDetail
     */
    public function updateManifest(string $noteId, string $userId, array $data): array
    {
        $this->access->assertVerified($userId);
        $ctx = $this->access->requireNoteEdit($noteId, $userId);
        $canvasId = (string) $ctx['note']['canvas_id'];

        $update = [];
        if (array_key_exists('title', $data)) {
            Validator::assert($data, ['title' => 'required|string|maxLength:200']);
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw ApiException::validation(['title' => ['обязательное поле']]);
            }
            $update['title'] = $title;
        }
        if (array_key_exists('importance', $data)) {
            Validator::assert($data, ['importance' => 'required|int|min:1|max:5']);
            $update['importance'] = (int) $data['importance'];
        }
        if (array_key_exists('isRelevant', $data)) {
            // строгая проверка: строка "false" не должна превращаться в true
            Validator::assert($data, ['isRelevant' => 'required|bool']);
            // PDO привязывает PHP false как пустую строку → 22P02 в Postgres;
            // колонка boolean корректно принимает '1'/'0'
            $update['is_relevant'] = $data['isRelevant'] ? 1 : 0;
        }

        $tagIds = null;
        if (array_key_exists('tagIds', $data)) {
            Validator::assert($data, ['tagIds' => 'required|array']);
            $tagIds = $this->assertCanvasTags($data['tagIds'], $canvasId);
        }

        $this->db->transaction(function () use ($noteId, $update, $tagIds): void {
            if ($update !== []) {
                $this->notes->update($noteId, $update);
            }
            if ($tagIds !== null) {
                $this->notes->syncTags($noteId, $tagIds);
            }
        });

        return $this->detail($noteId, $userId);
    }

    /**
     * PUT /notes/{id}/body: тело + перепарсинг [[wikilinks]] одной транзакцией,
     * чтобы при сбое парсера не остались ни новое тело без линков, ни линки
     * от старого тела.
     *
     * @param array<string, mixed> $data {bodyMd}
     *
     * @return array<string, mixed> NoteDetail
     */
    public function updateBody(string $noteId, string $userId, array $data): array
    {
        $this->access->assertVerified($userId);
        $ctx = $this->access->requireNoteEdit($noteId, $userId);
        $canvasId = (string) $ctx['note']['canvas_id'];

        Validator::assert($data, ['bodyMd' => 'required|string']);
        $bodyMd = (string) $data['bodyMd'];

        $this->db->transaction(function () use ($noteId, $canvasId, $bodyMd): void {
            $this->notes->updateBody($noteId, $bodyMd);
            $this->parser->parseAndStore($canvasId, $noteId, $bodyMd);
        });

        return $this->detail($noteId, $userId);
    }

    /**
     * PATCH /canvases/{id}/nodes/{noteId}: {x?, y?, width?} (≥editor канваса).
     *
     * @param array<string, mixed> $data
     */
    public function updatePosition(string $canvasId, string $noteId, string $userId, array $data): void
    {
        $this->access->assertVerified($userId);
        $this->access->requireCanvasEdit($canvasId, $userId);

        Validator::assert($data, [
            'x' => 'numeric',
            'y' => 'numeric',
            'width' => 'int|min:120|max:800',
        ]);

        $note = $this->notes->findById($noteId);
        if ($note === null || $note['canvas_id'] !== $canvasId) {
            throw ApiException::notFound();
        }

        $update = [];
        $x = self::numericField($data, 'x');
        if ($x !== null) {
            $update['x'] = $x;
        }
        $y = self::numericField($data, 'y');
        if ($y !== null) {
            $update['y'] = $y;
        }
        $width = self::intField($data, 'width');
        if ($width !== null) {
            $update['width'] = $width;
        }

        if ($update !== []) {
            $this->notes->update($noteId, $update);
        }
    }

    public function softDelete(string $noteId, string $userId): void
    {
        $this->access->assertVerified($userId);
        $this->access->requireNoteEdit($noteId, $userId);

        $this->notes->softDelete($noteId);
    }

    /**
     * Восстановление из «Удалённых»: 404 — нет заметки и tombstone;
     * 410 — заметка purged (docs/openapi.yaml), но только если вызывающий
     * видит канвас (политика приватности 404).
     *
     * @return array<string, mixed> Note
     */
    public function restore(string $noteId, string $userId): array
    {
        $this->access->assertVerified($userId);

        $note = $this->notes->findById($noteId);
        if ($note === null) {
            $tombstone = $this->deletedNotes->findByNoteId($noteId);
            if ($tombstone === null || $tombstone['canvas_id'] === null) {
                throw ApiException::notFound();
            }
            // 404 до 410: без прав на канвас существование tombstone не раскрываем
            $this->access->getCanvasContext((string) $tombstone['canvas_id'], $userId);

            throw ApiException::gone('Заметка удалена безвозвратно');
        }

        $this->access->requireNoteManage($noteId, $userId);
        $this->notes->restore($noteId);

        $row = $this->notes->findById($noteId);
        if ($row === null) {
            throw ApiException::notFound();
        }

        return Note::fromRow($row, $this->tagIds($noteId))->toArray();
    }

    public function purge(string $noteId, string $userId): void
    {
        $this->access->assertVerified($userId);
        $ctx = $this->access->requireNoteManage($noteId, $userId);
        $note = $ctx['note'];

        // Ключи до удаления строк: note_id — SET NULL, поэтому метаданные
        // удаляем явно в транзакции, объекты — после коммита (R17).
        $keys = $this->attachments->s3KeysByNoteId($noteId);

        $this->db->transaction(function () use ($noteId, $note, $userId): void {
            $this->deletedNotes->create(
                $noteId,
                (string) $note['canvas_id'],
                (string) $note['title'],
                $userId,
            );
            // До hardDelete: косвенные ссылки отвязываются и получают
            // deleted_target_id (docs/data-model.md:148); прямые уйдут
            // каскадом FK ON DELETE CASCADE.
            $this->indirectLinks->unlinkTarget($noteId);
            $this->attachments->deleteByNoteId($noteId);
            $this->notes->hardDelete($noteId);
        });

        $this->storage->deleteQuietly($keys);
    }

    /**
     * @return array<string, mixed> NoteDetail
     */
    private function detail(string $noteId, ?string $userId): array
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);

        return Note::fromRow($ctx['note'], $this->tagIds($noteId))->toDetailArray(
            (string) $ctx['note']['body_md'],
            $this->access->describeNoteRole($ctx),
            $this->attachmentPayloads($noteId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachmentPayloads(string $noteId): array
    {
        $rows = $this->attachments->findByNoteId($noteId);
        $payloads = [];
        foreach ($rows as $row) {
            $url = $this->storage->url((string) $row['s3_key'])['url'];
            $payloads[] = Attachment::fromRow($row)->toArray($url);
        }

        return $payloads;
    }

    /**
     * @return list<string>
     */
    private function tagIds(string $noteId): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->notes->findTags($noteId),
        ));
    }

    /**
     * tagIds должны быть массивом UUID, принадлежащих канвасу заметки —
     * иначе кросс-канвасная подвязка тегов и утечка их имён.
     *
     * @return list<string>
     */
    private function assertCanvasTags(mixed $tagIds, string $canvasId): array
    {
        if (!is_array($tagIds)) {
            throw ApiException::validation(['tagIds' => ['должно быть массивом']]);
        }

        $unique = [];
        foreach ($tagIds as $tagId) {
            if (!is_string($tagId) || preg_match(Validator::UUID_PATTERN, $tagId) !== 1) {
                throw ApiException::validation(['tagIds' => ['некорректный UUID']]);
            }
            $unique[$tagId] = true;
        }

        $ids = array_keys($unique);
        if ($ids === []) {
            return [];
        }

        $rows = $this->tags->findManyByIds($ids);
        if (count($rows) !== count($ids)) {
            throw ApiException::validation(['tagIds' => ['тег не существует']]);
        }
        foreach ($rows as $row) {
            if ($row['canvas_id'] !== $canvasId) {
                throw ApiException::validation(['tagIds' => ['тег принадлежит другому канвасу']]);
            }
        }

        return $ids;
    }

    private static function numericField(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function intField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    /** Лимит импорта из файла: 1 МБ (docs/api.md §from-text). */
    private const MAX_IMPORT_BYTES = 1_048_576;

    /**
     * Расширение импортируемого файла в нижнем регистре.
     * 422 — файла нет; 413 — PHP отверг по size-лимиту.
     *
     * @param array<string, mixed>|null $file
     */
    private static function importExtension(?array $file): string
    {
        if ($file === null) {
            throw ApiException::validation(['file' => ['обязателен файл .md или .txt']], 'Не передан файл');
        }

        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_OK;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ApiException::make(413, 'PAYLOAD_TOO_LARGE', 'Файл больше 1 МБ');
        }

        $name = is_string($file['name'] ?? null) ? $file['name'] : '';
        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($error !== UPLOAD_ERR_OK || $tmpName === '' || $name === '') {
            throw ApiException::validation(['file' => ['обязателен файл .md или .txt']], 'Не передан файл');
        }

        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    /**
     * Содержимое файла — будущее bodyMd. 413 — больше 1 МБ; 422 — прочитать
     * не удалось (файл исчез/битый tmp_name).
     *
     * @param array<string, mixed> $file
     */
    private static function importBody(array $file): string
    {
        $size = is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0;
        if ($size > self::MAX_IMPORT_BYTES) {
            throw ApiException::make(413, 'PAYLOAD_TOO_LARGE', 'Файл больше 1 МБ');
        }

        $contents = @file_get_contents((string) $file['tmp_name']);
        if ($contents === false) {
            throw ApiException::validation(['file' => ['не удалось прочитать файл']], 'Не удалось прочитать файл');
        }
        if (strlen($contents) > self::MAX_IMPORT_BYTES) {
            throw ApiException::make(413, 'PAYLOAD_TOO_LARGE', 'Файл больше 1 МБ');
        }

        return $contents;
    }

    /**
     * Заголовок: поле title, иначе имя файла без расширения
     * (docs/api.md §from-text).
     *
     * @param array<string, mixed> $fields
     */
    private static function importTitle(array $fields, string $fileName): string
    {
        $title = is_string($fields['title'] ?? null) ? trim($fields['title']) : '';
        if ($title !== '') {
            return $title;
        }

        $fromName = trim(pathinfo($fileName, PATHINFO_FILENAME));
        if ($fromName !== '') {
            return mb_substr($fromName, 0, 200);
        }

        return 'Без названия';
    }
}
