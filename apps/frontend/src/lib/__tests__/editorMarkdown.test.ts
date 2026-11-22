// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { Editor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from '@tiptap/markdown'

import { WikiLink } from '@/components/editor/extensions/WikiLink'
import {
  normalizeMarkdown,
  prepareMarkdownForEditor,
  restoreWikiLinks,
  toStoredMarkdown,
} from '@/lib/editorMarkdown'

function roundTrip(markdown: string): string {
  const prepared = prepareMarkdownForEditor(markdown)
  const editor = new Editor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: {
          openOnClick: false,
          autolink: true,
          protocols: ['http', 'https', 'mailto'],
          HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
        },
      }),
      WikiLink,
      Markdown,
    ],
    content: prepared.markdown,
    contentType: 'markdown',
  })
  const result = toStoredMarkdown(editor.getMarkdown(), prepared.wikiLinks)
  editor.destroy()

  return result
}

describe('editorMarkdown (R28)', () => {
  it('normalizuet CRLF i zavershayushchiy perevod stroki', () => {
    expect(normalizeMarkdown('a\r\nb')).toBe('a\nb\n')
    expect(normalizeMarkdown('')).toBe('')
    expect(normalizeMarkdown('a\n')).toBe('a\n')
  })

  it('sokhranyaet zagolovki H1–H3, zhirnyy, kursiv, spiski i kod', () => {
    const md =
      '# Zagolovok 1\n\n## Zagolovok 2\n\n### Zagolovok 3\n\n**zhirnyy** *kursiv* `kod`\n\n- odin\n- dva\n\n```js\nconst a = 1\n```\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('sokhranyaet ssylki', () => {
    expect(roundTrip('[Ssylka](https://example.com)\n')).toBe('[Ssylka](https://example.com)\n')
  })

  it('ne ekraniruet [[wikilinks]] pri round-trip (R30)', () => {
    const md = 'See [[123e4567-e89b-12d3-a456-426614174000|Zametka B]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('podderzhivaet neskolko wikilinks v tekste (R30)', () => {
    const md = 'Tekst [[id-1|Pervaya]] i [[id-2|Vtoraya]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('sokhranyaet wikilink bez label kak [[id]] (R30)', () => {
    const md = 'See [[id-1]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('vosstanavlivaet vruchnuyu nabrannuyu [[ssylku]] bez prepare', () => {
    expect(restoreWikiLinks('\\[\\[id|Zagolovok\\]\\]\n')).toBe('[[id|Zagolovok]]\n')
  })

  it('prepareMarkdownForEditor bolshe ne tokeniziruet wikilinks (R30)', () => {
    const prepared = prepareMarkdownForEditor('[[id|A]]\n')
    expect(prepared.markdown).toBe('[[id|A]]\n')
    expect(prepared.wikiLinks).toEqual([])
  })

  it('komanda setWikiLink vstavlyaet wikilink i serializuet v markdown (R30)', () => {
    const prepared = prepareMarkdownForEditor('')
    const editor = new Editor({
      extensions: [
        StarterKit.configure({
          heading: { levels: [1, 2, 3] },
          link: {
            openOnClick: false,
            autolink: true,
            protocols: ['http', 'https', 'mailto'],
            HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
          },
        }),
        WikiLink,
        Markdown,
      ],
      content: prepared.markdown,
      contentType: 'markdown',
    })

    editor.commands.setWikiLink({ noteId: 'note-1', label: 'Zametka 1' })
    const md = toStoredMarkdown(editor.getMarkdown(), prepared.wikiLinks)
    editor.destroy()

    expect(md).toContain('[[note-1|Zametka 1]]')
  })
})

describe('editorMarkdown additional coverage', () => {
  it('normalizeMarkdown handles empty/CR/CRLF', () => {
    expect(normalizeMarkdown('')).toBe('')
    expect(normalizeMarkdown('a\r\nb\r')).toBe('a\nb\n')
    expect(normalizeMarkdown('a\n')).toBe('a\n')
  })

  it('prepareMarkdownForEditor normalizes and returns empty wikiLinks', () => {
    expect(prepareMarkdownForEditor('a\r\n')).toEqual({ markdown: 'a\n', wikiLinks: [] })
  })

  it('restoreWikiLinks replaces tokens and unescapes multiple wikilinks', () => {
    const value = 'A token1 B \\[\\[id2|Two\\]\\]'
    const out = restoreWikiLinks(value, [{ token: 'token1', link: '[[id1|One]]' }])
    expect(out).toBe('A [[id1|One]] B [[id2|Two]]')
  })

  it('toStoredMarkdown normalizes and restores', () => {
    expect(toStoredMarkdown('\\[\\[id|L\\]\\]\r\n', [])).toBe('[[id|L]]\n')
  })
})
