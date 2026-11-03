<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\IndirectLinkRepository;
use Vistynq\Repositories\NoteRepository;

/**
 * Разбор [[wikilinks]] markdown-тела и пересоздание indirect_links
 * (docs/architecture.md §10, docs/ROADMAP.md R12/R18).
 *
 * Резолв цели — двумя запросами на все ссылки (uuid-цели одним IN,
 * заголовки одним IN) вместо запроса на каждую [[...]]; повторяющиеся
 * цели дают одну строку.
 */
final class LinkParser
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly IndirectLinkRepository $indirectLinks,
    ) {
    }

    public function parseAndStore(string $canvasId, string $noteId, string $bodyMd): void
    {
        // Полная замена строк заметки: старые линки уходят всегда, даже если
        // в теле не осталось ни одной [[ссылки]].
        $this->indirectLinks->deleteForSource($noteId);

        preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', $bodyMd, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return;
        }

        /** @var array<string, true> $targets */
        $targets = [];
        foreach ($matches as $match) {
            $rawTarget = trim($match[1]);
            if ($rawTarget !== '') {
                $targets[$rawTarget] = true;
            }
        }
        if ($targets === []) {
            return;
        }

        $rawTargets = array_keys($targets);
        $resolved = $this->resolve($canvasId, $rawTargets);

        foreach ($rawTargets as $rawTarget) {
            $toNoteId = $resolved[$rawTarget] ?? null;
            $this->indirectLinks->insert($noteId, $toNoteId, '[[' . $rawTarget . ']]', $toNoteId !== null);
        }
    }

    /**
     * @param list<string> $rawTargets
     *
     * @return array<string, string|null> rawTarget => id заметки либо null
     */
    private function resolve(string $canvasId, array $rawTargets): array
    {
        $uuids = [];
        $titles = [];
        foreach ($rawTargets as $rawTarget) {
            if (preg_match(self::UUID_PATTERN, $rawTarget) === 1) {
                $uuids[] = $rawTarget;
            } else {
                $titles[] = $rawTarget;
            }
        }

        /** @var array<string, string|null> $resolved */
        $resolved = array_fill_keys($rawTargets, null);

        // uuid в теле может быть в верхнем регистре, id в БД — канонический
        // нижний; сравниваем без учёта регистра, чтобы цель не «терялась».
        $byLowerId = [];
        foreach ($this->notes->findVisibleIdsInCanvas($canvasId, $uuids) as $id) {
            $byLowerId[mb_strtolower($id)] = $id;
        }
        foreach ($uuids as $rawTarget) {
            $resolved[$rawTarget] = $byLowerId[mb_strtolower($rawTarget)] ?? null;
        }

        $byLowerTitle = $this->notes->findVisibleByLowerTitles($canvasId, $titles);
        foreach ($titles as $title) {
            $resolved[$title] = $byLowerTitle[mb_strtolower($title)] ?? null;
        }

        return $resolved;
    }
}
