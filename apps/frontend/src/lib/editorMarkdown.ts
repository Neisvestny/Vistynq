/**
 * Мост между markdown-телом заметки (API) и Tiptap (R28).
 *
 * `[[uuid|Заголовок]]` — не обычный markdown: сериализатор @tiptap/markdown
 * экранирует квадратные скобки (`\[\[...\]\]`), что сломало бы косвенные
 * ссылки (R15/R30). Перед загрузкой в редактор такие вставки заменяются
 * непрозрачным токеном, а после `getMarkdown()` — восстанавливаются.
 */

const WIKI_LINK_RE = /\[\[[^[\]\n]+\]\]/g
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
 * Заменить `[[...]]` на токены, которые markdown-сериализатор не тронет.
 * Возврат пар token→link нужен для обратной подстановки после getMarkdown().
 */
export function prepareMarkdownForEditor(value: string): PreparedMarkdown {
  const normalized = normalizeMarkdown(value)
  const wikiLinks: WikiLinkToken[] = []
  const used = new Set<string>()

  const markdown = normalized.replace(WIKI_LINK_RE, (link) => {
    let token = `vistynqwikilink${wikiLinks.length}zq`
    while (normalized.includes(token) || used.has(token)) {
      token += 'x'
    }
    used.add(token)
    wikiLinks.push({ token, link })

    return token
  })

  return { markdown, wikiLinks }
}

/**
 * Обратная подстановка токенов + поддержка ссылок, набранных вручную уже
 * в редакторе (Tiptap вернёт их экранированными).
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
