<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vistynq\Services\LinkParserService;

require_once __DIR__ . '/Fakes/FakeNoteRepository.php';
require_once __DIR__ . '/Fakes/FakeIndirectLinkRepository.php';

/**
 * Табличные unit-кейсы разбора [[wikilinks]] (roadmap R15):
 * изолированный regex-парсер, резолв по uuid и заголовку, дедуп по цели,
 * полная замена строк заметки при каждом сохранении тела.
 *
 * Контракт (docs/architecture.md §4, docs/data-model.md §indirect_links):
 *  - живая цель → resolved = true, to_note_id = id;
 *  - опечатка/чужой канвас → resolved = false, to_note_id = null;
 *  - повторяющаяся цель → одна строка (UNIQUE (from_note_id, raw_target));
 *  - пустое тело → старые линки удаляются.
 */
final class LinkParserServiceTest extends TestCase
{
    private const CANVAS = 'canvas-1';
    private const OTHER_CANVAS = 'canvas-2';
    private const SOURCE = 'note-source';

    private const TARGET_UUID = '550e8400-e29b-41d4-a716-446655440000';
    private const TARGET_TITLE = 'Целевая заметка';

    private FakeNoteRepository $notes;
    private FakeIndirectLinkRepository $indirectLinks;
    private LinkParserService $parser;

    protected function setUp(): void
    {
        $this->notes = new FakeNoteRepository();
        $this->indirectLinks = new FakeIndirectLinkRepository();
        $this->parser = new LinkParserService($this->notes, $this->indirectLinks);

        $this->notes->add([
            'id' => self::TARGET_UUID,
            'canvas_id' => self::CANVAS,
            'author_id' => 'user-1',
            'title' => self::TARGET_TITLE,
            'importance' => 3,
            'is_relevant' => true,
            'x' => 0.0,
            'y' => 0.0,
            'width' => 200,
            'deleted_at' => null,
        ]);
        $this->notes->add([
            'id' => 'note-foreign',
            'canvas_id' => self::OTHER_CANVAS,
            'author_id' => 'user-2',
            'title' => 'Чужая',
            'importance' => 1,
            'is_relevant' => true,
            'x' => 0.0,
            'y' => 0.0,
            'width' => 200,
            'deleted_at' => null,
        ]);
    }

    /**
     * raw_target хранится с скобками — это исходный текст [[...]],
     * который GraphService отдаёт как label «ссылка удалена» (docs/api.md).
     *
     * @param list<array{rawTarget: string, resolved: bool, toNoteId: string|null}> $expected
     *
     * @return list<array{rawTarget: string, resolved: bool, toNoteId: string|null}>
     */
    private static function wrapped(array $expected): array
    {
        return array_map(
            static function (array $row): array {
                $row['rawTarget'] = '[[' . $row['rawTarget'] . ']]';

                return $row;
            },
            $expected,
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<array{rawTarget: string, resolved: bool, toNoteId: string|null}>}>
     */
    public static function parseCases(): array
    {
        $uuid = self::TARGET_UUID;

        $cases = [
            'без ссылок' => [
                'Просто текст без связей.',
                [],
            ],
            'пустое тело' => [
                '',
                [],
            ],
            'uuid-цель' => [
                'Смотри [[' . $uuid . ']] тут.',
                [['rawTarget' => $uuid, 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'uuid-цель с label' => [
                '[[' . $uuid . '|Подпись]]',
                [['rawTarget' => $uuid, 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'uuid в верхнем регистре' => [
                '[[' . strtoupper($uuid) . ']]',
                [['rawTarget' => strtoupper($uuid), 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'заголовок без учёта регистра' => [
                '[[целевая ЗАМЕТКА]]',
                [['rawTarget' => 'целевая ЗАМЕТКА', 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'заголовок с label' => [
                '[[' . self::TARGET_TITLE . '|Метка]]',
                [['rawTarget' => self::TARGET_TITLE, 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'цель-опечатка не резолвится' => [
                '[[Несуществующая]]',
                [['rawTarget' => 'Несуществующая', 'resolved' => false, 'toNoteId' => null]],
            ],
            'чужой канвас не резолвится' => [
                '[[Чужая]]',
                [['rawTarget' => 'Чужая', 'resolved' => false, 'toNoteId' => null]],
            ],
            'несуществующий uuid не резолвится' => [
                '[[00000000-0000-4000-8000-000000000000]]',
                [['rawTarget' => '00000000-0000-4000-8000-000000000000', 'resolved' => false, 'toNoteId' => null]],
            ],
            'несколько целей' => [
                '[[Несуществующая]] и [[' . $uuid . '|Подпись]]',
                [
                    ['rawTarget' => 'Несуществующая', 'resolved' => false, 'toNoteId' => null],
                    ['rawTarget' => $uuid, 'resolved' => true, 'toNoteId' => $uuid],
                ],
            ],
            'повторяющаяся цель — одна строка' => [
                '[[' . $uuid . ']] снова [[' . $uuid . '|Другая метка]]',
                [['rawTarget' => $uuid, 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'разные написания — отдельные строки (UNIQUE по raw_target)' => [
                '[[Целевая заметка]] и [[целевая заметка]]',
                [
                    ['rawTarget' => 'Целевая заметка', 'resolved' => true, 'toNoteId' => $uuid],
                    ['rawTarget' => 'целевая заметка', 'resolved' => true, 'toNoteId' => $uuid],
                ],
            ],
            'whitespace вокруг цели обрезается' => [
                '[[  ' . $uuid . '  ]]',
                [['rawTarget' => $uuid, 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'пустая цель пропускается' => [
                '[[]] и [[' . $uuid . ']]',
                [['rawTarget' => $uuid, 'resolved' => true, 'toNoteId' => $uuid]],
            ],
            'текст вне скобок не парсится' => [
                '[обычные скобки] и [[',
                [],
            ],
        ];

        return array_map(
            static fn (array $case): array => [$case[0], self::wrapped($case[1])],
            $cases,
        );
    }

    #[DataProvider('parseCases')]
    public function testParseTable(string $bodyMd, array $expected): void
    {
        $this->parser->parseAndStore(self::CANVAS, self::SOURCE, $bodyMd);

        $rows = $this->indirectLinks->findEdgesBySource([self::SOURCE]);
        $actual = array_map(
            static fn (array $row): array => [
                'rawTarget' => (string) $row['raw_target'],
                'resolved' => (bool) $row['resolved'],
                'toNoteId' => $row['to_note_id'] !== null ? (string) $row['to_note_id'] : null,
            ],
            $rows,
        );

        $this->assertSame($expected, $actual);
    }

    public function testResaveReplacesAllRows(): void
    {
        $this->parser->parseAndStore(self::CANVAS, self::SOURCE, '[[' . self::TARGET_UUID . ']] [[Несуществующая]]');
        $this->assertCount(2, $this->indirectLinks->findEdgesBySource([self::SOURCE]));

        // Полная замена строк заметки: старые линки уходят всегда,
        // даже если в новом теле не осталось ни одной [[ссылки]].
        $this->parser->parseAndStore(self::CANVAS, self::SOURCE, 'Без ссылок');

        $this->assertSame([], $this->indirectLinks->findEdgesBySource([self::SOURCE]));
    }

    public function testRowsOfOtherSourcesAreUntouched(): void
    {
        $this->indirectLinks->insert('note-other', null, '[[Другая]]', false);

        $this->parser->parseAndStore(self::CANVAS, self::SOURCE, '[[' . self::TARGET_UUID . ']]');

        $this->assertCount(1, $this->indirectLinks->findEdgesBySource(['note-other']));
        $this->assertCount(1, $this->indirectLinks->findEdgesBySource([self::SOURCE]));
    }
}
