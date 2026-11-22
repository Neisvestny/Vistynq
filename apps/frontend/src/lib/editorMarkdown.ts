/**
 * Мост между markdown-телом заметки (API) и Tiptap (R28, R30).
 *
 * `[[uuid|Заголовок]]` обрабатывается Tiptap-расширением WikiLink (R30).
 * Этот модуль оставляет API prepare/restore для совместимости и выполняет
 * нормализацию markdown и снятие экранирования `\[\[...\]\]`, если markdown
 * сериализатор всё же экранирует квадратные скобки.
 */

const ESCAPED_WIKI_LINK_RE = /\\\[\\\[([^[\]\n]+)\\\]\\\]/g

export interface WikiLinkToken {
  token: string
  link: string
}

export interface PreparedMarkdown {
  markdown: string
  wikiLinks: WikiLinkToken[]
}

/** LF вместо CRLF; один завершающий перевод строки у непустого текста. */
export function normalizeMarkdown(value: string): string {
  const lf = value.replace(/\r\n?/g, '\n')
  if (lf === '' || lf.endsWith('\n')) return lf
  return `${lf}\n`
}

/**
 * Подготовка markdown к загрузке в редактор.
 * WikiLink теперь парсится Tiptap-расширением, поэтому токенизация не нужна,
 * но API сохранён для совместимости и тестов.
 */
export function prepareMarkdownForEditor(value: string): PreparedMarkdown {
  return { markdown: normalizeMarkdown(value), wikiLinks: [] }
}

/**
 * Снимает экранирование `\[\[...\]\]` и подставляет legacy-токены, если они есть.
 */
export function restoreWikiLinks(value: string, wikiLinks: WikiLinkToken[] = []): string {
  let result = value
  for (const { token, link } of wikiLinks) {
    result = result.split(token).join(link)
  }

  return result.replace(ESCAPED_WIKI_LINK_RE, (_match, body: string) => `[[${body}]]`)
}

/** Полный цикл: prepare → Tiptap → restore. */
export function toStoredMarkdown(value: string, wikiLinks: WikiLinkToken[] = []): string {
  return normalizeMarkdown(restoreWikiLinks(value, wikiLinks))
}
