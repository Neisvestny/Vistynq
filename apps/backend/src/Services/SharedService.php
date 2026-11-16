<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\Canvas;
use Vistynq\Models\Note;
use Vistynq\Repositories\CanvasRepositoryInterface;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

/**
 * Публичные read-only страницы /shared (docs/api.md §Публичный доступ, R16).
 *
 * Аноним видит только public-заметки public-канваса: без access-деталей,
 * без removed-рёбер, без приватных заметок и их тегов. Резолв wikilinks
 * ограничен публичными живыми заметками того же канваса.
 */
final class SharedService
{
    private const SLUG_PATTERN = '/^[a-z0-9-]{1,64}$/';

    public function __construct(
        private readonly CanvasRepositoryInterface $canvases,
        private readonly NoteRepositoryInterface $notes,
        private readonly GraphService $graph,
        private readonly MarkdownRenderer $renderer,
    ) {
    }

    /**
     * @return array{
     *     canvas: array<string,mixed>,
     *     nodes: list<array<string,mixed>>,
     *     edges: list<array<string,mixed>>,
     *     tags: list<array<string,mixed>>
     * }
     */
    public function canvas(string $slug): array
    {
        $canvas = $this->findPublicCanvas($slug);
        $canvasId = (string) $canvas['id'];

        // Анонимная ветка GraphService: только public-заметки, removed-рёбра
        // подавляются (userId = null).
        $graph = $this->graph->build($canvasId, null, 'viewer');

        $payload = Canvas::fromRow($canvas, 'viewer')->toArray();
        unset($payload['access']);

        /** @var array<string, true> $usedTagNames */
        $usedTagNames = [];
        foreach ($graph['nodes'] as $node) {
            foreach ($node['tags'] as $tagName) {
                $usedTagNames[(string) $tagName] = true;
            }
        }

        $tags = array_values(array_filter(
            $graph['tags'],
            static fn (array $tag): bool => isset($usedTagNames[(string) $tag['name']]),
        ));

        return [
            'canvas' => $payload,
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
            'tags' => $tags,
        ];
    }

    /**
     * @return array{note: array<string,mixed>, bodyHtml: string}
     */
    public function note(string $slug, string $noteId): array
    {
        $canvas = $this->findPublicCanvas($slug);
        $canvasId = (string) $canvas['id'];

        $row = $this->notes->findById($noteId);
        if (
            $row === null
            || (string) $row['canvas_id'] !== $canvasId
            || $row['deleted_at'] !== null
            || (string) ($row['visibility'] ?? 'public') !== 'public'
        ) {
            throw ApiException::notFound('Заметка не найдена');
        }

        $tagIds = array_values(array_map(
            static fn (array $tag): string => (string) $tag['id'],
            $this->notes->findTags($noteId),
        ));
        $bodyMd = (string) $row['body_md'];
        $resolved = $this->resolvePublicTargets($canvasId, $this->renderer->extractTargets($bodyMd));

        return [
            'note' => Note::fromRow($row, $tagIds)->toDetailArray($bodyMd, 'viewer'),
            'bodyHtml' => $this->renderer->render($bodyMd, $resolved, $slug),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function findPublicCanvas(string $slug): array
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw ApiException::notFound('Канвас не найден');
        }

        $canvas = $this->canvases->findByPublicSlug($slug);
        if ($canvas === null || (string) $canvas['access_level'] !== 'public') {
            throw ApiException::notFound('Канвас не найден');
        }

        return $canvas;
    }

    /**
     * @param list<string> $targets
     *
     * @return array<string, string> lower(target) => noteId, только public
     */
    private function resolvePublicTargets(string $canvasId, array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $uuids = [];
        $titles = [];
        foreach ($targets as $target) {
            if (preg_match(Validator::UUID_PATTERN, $target) === 1) {
                $uuids[] = $target;
            } else {
                $titles[] = $target;
            }
        }

        $byLowerId = [];
        foreach ($this->notes->findPublicIdsInCanvas($canvasId, $uuids) as $id) {
            $byLowerId[mb_strtolower($id)] = $id;
        }
        $byLowerTitle = $this->notes->findPublicByLowerTitles($canvasId, $titles);

        $resolved = [];
        foreach ($targets as $target) {
            $key = mb_strtolower($target);
            $noteId = $byLowerId[$key] ?? $byLowerTitle[$key] ?? null;
            if ($noteId !== null) {
                $resolved[$key] = $noteId;
            }
        }

        return $resolved;
    }
}
