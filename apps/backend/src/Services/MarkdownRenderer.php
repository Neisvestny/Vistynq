<?php

declare(strict_types=1);

namespace Vistynq\Services;

/**
 * Минимальный escape-first renderer markdown для публичных /shared-страниц
 * (R16). Контракт bodyHtml не изменится в R18 — там появится полноценная
 * санитизация/расширенный markdown.
 *
 * Инвариант безопасности: любой пользовательский текст проходит через
 * htmlspecialchars; HTML-теги генерируются только из закрытого whitelist.
 * Сырой HTML из bodyMd не проходит никогда. Ссылки [text](url) допускаются
 * только с http/https.
 *
 * Недоступная [[wikilink]]-цель (приватная/удалённая/чужая) превращается в
 * plain text: label, иначе raw target — без скобок и без href.
 */
final class MarkdownRenderer
{
    private const WIKILINK_PATTERN = '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u';

    /**
     * Сырые цели wikilinks из тела (для пакетного резолва вызывающим кодом).
     *
     * @return list<string>
     */
    public function extractTargets(string $bodyMd): array
    {
        preg_match_all(self::WIKILINK_PATTERN, $bodyMd, $matches, PREG_SET_ORDER);
        /** @var array<string, string> $targets lower(target) => first spelling */
        $targets = [];
        foreach ($matches as $match) {
            $target = trim((string) ($match[1] ?? ''));
            if ($target !== '') {
                $targets[mb_strtolower($target)] ??= $target;
            }
        }

        return array_values($targets);
    }

    /**
     * @param array<string, string> $resolvedByLowerTarget lower(rawTarget) => noteId
     */
    public function render(string $bodyMd, array $resolvedByLowerTarget, string $slug): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $bodyMd));
        $count = count($lines);
        $blocks = [];
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^```/', $line) === 1) {
                $code = [];
                $i++;
                while ($i < $count && preg_match('/^```/', $lines[$i]) !== 1) {
                    $code[] = $lines[$i];
                    $i++;
                }
                if ($i < $count) {
                    $i++;
                }
                $blocks[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $heading) === 1) {
                $level = strlen($heading[1]);
                $blocks[] = "<h{$level}>" . $this->inline(trim($heading[2]), $resolvedByLowerTarget, $slug) . "</h{$level}>";
                $i++;
                continue;
            }

            if (preg_match('/^[-*+]\s+(.*)$/u', $line) === 1) {
                $items = [];
                while ($i < $count && preg_match('/^[-*+]\s+(.*)$/u', $lines[$i], $item) === 1) {
                    $items[] = '<li>' . $this->inline($item[1], $resolvedByLowerTarget, $slug) . '</li>';
                    $i++;
                }
                $blocks[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.*)$/u', $line) === 1) {
                $items = [];
                while ($i < $count && preg_match('/^\d+\.\s+(.*)$/u', $lines[$i], $item) === 1) {
                    $items[] = '<li>' . $this->inline($item[1], $resolvedByLowerTarget, $slug) . '</li>';
                    $i++;
                }
                $blocks[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/u', $line) === 1) {
                $quotes = [];
                while ($i < $count && preg_match('/^>\s?(.*)$/u', $lines[$i], $quote) === 1) {
                    $quotes[] = $this->inline($quote[1], $resolvedByLowerTarget, $slug);
                    $i++;
                }
                $blocks[] = '<blockquote><p>' . implode('<br>', $quotes) . '</p></blockquote>';
                continue;
            }

            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '' && !self::isBlockStart($lines[$i])) {
                $paragraph[] = $this->inline($lines[$i], $resolvedByLowerTarget, $slug);
                $i++;
            }
            if ($paragraph === []) {
                $paragraph[] = $this->inline($line, $resolvedByLowerTarget, $slug);
                $i++;
            }
            $blocks[] = '<p>' . implode('<br>', $paragraph) . '</p>';
        }

        return implode("\n", $blocks);
    }

    /**
     * @param array<string, string> $resolvedByLowerTarget
     */
    private function inline(string $text, array $resolvedByLowerTarget, string $slug): string
    {
        /** @var list<string> $tokens */
        $tokens = [];

        $text = preg_replace_callback('/`([^`]+)`/u', static function (array $match) use (&$tokens): string {
            $tokens[] = '<code>' . self::escape($match[1]) . '</code>';

            return "\x00" . (count($tokens) - 1) . "\x00";
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function (array $match) use (&$tokens): string {
            $url = $match[2];
            if (preg_match('#^https?://#i', $url) !== 1) {
                return $match[0];
            }
            $tokens[] = '<a href="' . self::escape($url) . '" rel="noopener noreferrer">'
                . self::escape($match[1]) . '</a>';

            return "\x00" . (count($tokens) - 1) . "\x00";
        }, $text) ?? $text;

        $out = '';
        $offset = 0;
        preg_match_all(self::WIKILINK_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $position = (int) $match[0][1];
            $out .= self::emphasis(self::escape(substr($text, $offset, $position - $offset)));

            $target = trim((string) $match[1][0]);
            $label = isset($match[2]) && trim((string) $match[2][0]) !== ''
                ? trim((string) $match[2][0])
                : $target;
            $noteId = $resolvedByLowerTarget[mb_strtolower($target)] ?? null;

            if ($noteId === null || $noteId === '') {
                $out .= self::escape($label);
            } else {
                $out .= '<a href="/shared/canvas/' . rawurlencode($slug) . '/note/'
                    . rawurlencode($noteId) . '">' . self::escape($label) . '</a>';
            }

            $offset = $position + strlen((string) $match[0][0]);
        }
        $out .= self::emphasis(self::escape(substr($text, $offset)));

        foreach ($tokens as $index => $html) {
            $out = str_replace("\x00" . $index . "\x00", $html, $out);
        }

        return $out;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function emphasis(string $escaped): string
    {
        $out = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped);
        $out = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $out ?? $escaped);

        return $out ?? $escaped;
    }

    private static function isBlockStart(string $line): bool
    {
        return preg_match('/^(```|#{1,6}\s|[-*+]\s|\d+\.\s|>)/u', $line) === 1;
    }
}
