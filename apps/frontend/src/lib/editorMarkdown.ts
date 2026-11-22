/**
 * Bridge between note markdown body (API) and Tiptap (R28, R30).
 *
 * `[[uuid|Title]]` is handled by Tiptap WikiLink extension (R30).
 * This module keeps prepare/restore API for compatibility and performs
 * markdown normalization and unescaping of `\[\[...\]\]` if markdown
 * serializer still escapes square brackets.
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

/** LF instead of CRLF; single trailing newline for non-empty text. */
export function normalizeMarkdown(value: string): string {
  const lf = value.replace(/\r\n?/g, '\n')
  if (lf === '' || lf.endsWith('\n')) return lf
  return `${lf}\n`
}

/**
 * Prepare markdown for loading into editor.
 * WikiLink is now parsed by Tiptap extension, so tokenization is not needed,
 * but API is kept for compatibility and tests.
 */
export function prepareMarkdownForEditor(value: string): PreparedMarkdown {
  return { markdown: normalizeMarkdown(value), wikiLinks: [] }
}

/**
 * Unescapes `\[\[...\]\]` and substitutes legacy tokens if present.
 */
export function restoreWikiLinks(value: string, wikiLinks: WikiLinkToken[] = []): string {
  let result = value
  for (const { token, link } of wikiLinks) {
    result = result.split(token).join(link)
  }

  return result.replace(ESCAPED_WIKI_LINK_RE, (_match, body: string) => `[[${body}]]`)
}

/** Full cycle: prepare -> Tiptap -> restore. */
export function toStoredMarkdown(value: string, wikiLinks: WikiLinkToken[] = []): string {
  return normalizeMarkdown(restoreWikiLinks(value, wikiLinks))
}
