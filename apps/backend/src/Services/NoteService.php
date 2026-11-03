<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Throwable;
use Vistynq\Models\Note;
use Vistynq\Repositories\DeletedNoteRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Repositories\TagRepository;
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
        private readonly NoteRepository $notes,
        private readonly DeletedNoteRepository $deletedNotes,
        private readonly TagRepository $tags,
        private readonly AccessService $access,
        private readonly LinkParser $parser,
        private readonly Database $db,
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
     * @return array<string, mixed> NoteDetail (docs/openapi.yaml)
     */
    public function get(string $noteId, ?string $userId): array
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);

        return Note::fromRow($ctx['note'], $this->tagIds($noteId))->toDetailArray(
            (string) $ctx['note']['body_md'],
            $this->access->describeNoteRole($ctx),
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

        $this->transactional(function () use ($noteId, $update, $tagIds): void {
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

        $this->transactional(function () use ($noteId, $canvasId, $bodyMd): void {
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

        $this->transactional(function () use ($noteId, $note, $userId): void {
            $this->deletedNotes->create(
                $noteId,
                (string) $note['canvas_id'],
                (string) $note['title'],
                $userId,
            );
            $this->notes->hardDelete($noteId);
        });
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
        );
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

    /**
     * Выполняет $fn одной транзакцией: исключение откатывает все изменения.
     *
     * @param callable(): void $fn
     */
    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $fn();

            return;
        }

        $pdo->beginTransaction();
        try {
            $fn();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
}
