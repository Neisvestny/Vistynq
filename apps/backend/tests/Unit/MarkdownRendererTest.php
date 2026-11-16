<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Services\MarkdownRenderer;

/**
 * Unit-тесты escape-first рендера публичных страниц (R16).
 *
 * Инвариант: пользовательский текст не может превратиться в HTML или
 * опасный href — рендер строится только из закрытого whitelist.
 */
final class MarkdownRendererTest extends TestCase
{
    private const SLUG = 'abc123';
    private const NOTE_ID = '11111111-1111-4111-8111-111111111111';

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    public function testRawHtmlIsEscaped(): void
    {
        $html = $this->renderer->render('<script>alert("x")</script>', [], self::SLUG);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testBlockWhitelist(): void
    {
        $markdown = "# Заголовок\n\nТекст\n\n- один\n- два\n\n1. раз\n2. два\n\n> цитата\n\n```\ncode\n```";

        $html = $this->renderer->render($markdown, [], self::SLUG);

        $this->assertStringContainsString('<h1>Заголовок</h1>', $html);
        $this->assertStringContainsString('<ul><li>один</li><li>два</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>раз</li><li>два</li></ol>', $html);
        $this->assertStringContainsString('<blockquote><p>цитата</p></blockquote>', $html);
        $this->assertStringContainsString('<pre><code>code</code></pre>', $html);
    }

    public function testInlineFormattingIsEscaped(): void
    {
        $html = $this->renderer->render('**bold <b>** и *it* и `code <x>`', [], self::SLUG);

        $this->assertStringContainsString('<strong>bold &lt;b&gt;</strong>', $html);
        $this->assertStringContainsString('<em>it</em>', $html);
        $this->assertStringContainsString('<code>code &lt;x&gt;</code>', $html);
    }

    public function testOnlyHttpLinksBecomeAnchors(): void
    {
        $html = $this->renderer->render(
            '[ok](https://example.com/a?b=1&c=2) [bad](javascript:alert(1))',
            [],
            self::SLUG,
        );

        $this->assertStringContainsString(
            '<a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer">ok</a>',
            $html,
        );
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('[bad](javascript:alert(1))', $html);
    }

    public function testResolvedWikilinkBecomesSharedLink(): void
    {
        $resolved = ['цель' => self::NOTE_ID];

        $html = $this->renderer->render('[[Цель|подпись]]', $resolved, self::SLUG);

        $this->assertStringContainsString(
            '<a href="/shared/canvas/' . self::SLUG . '/note/' . self::NOTE_ID . '">подпись</a>',
            $html,
        );
    }

    public function testUnresolvedWikilinkIsPlainText(): void
    {
        $html = $this->renderer->render('[[Секретная|<img src=x>]] и [[Другая]]', [], self::SLUG);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('&lt;img src=x&gt;', $html);
        $this->assertStringContainsString('Другая', $html);
    }

    public function testWikilinkTargetsAreExtractedOnce(): void
    {
        $targets = $this->renderer->extractTargets('[[ A ]] [[a]] [[B|label]]');

        $this->assertSame(['A', 'B'], $targets);
    }
}
