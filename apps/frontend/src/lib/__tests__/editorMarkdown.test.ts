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
  it('нормализует CRLF и завершающий перевод строки', () => {
    expect(normalizeMarkdown('a\r\nb')).toBe('a\nb\n')
    expect(normalizeMarkdown('')).toBe('')
    expect(normalizeMarkdown('a\n')).toBe('a\n')
  })

  it('сохраняет заголовки H1–H3, жирный, курсив, списки и код', () => {
    const md =
      '# Заголовок 1\n\n## Заголовок 2\n\n### Заголовок 3\n\n**жирный** *курсив* `код`\n\n- один\n- два\n\n```js\nconst a = 1\n```\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('сохраняет ссылки', () => {
    expect(roundTrip('[Ссылка](https://example.com)\n')).toBe('[Ссылка](https://example.com)\n')
  })

  it('не экранирует [[wikilinks]] при round-trip (R30)', () => {
    const md = 'См. [[123e4567-e89b-12d3-a456-426614174000|Заметка Б]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('поддерживает несколько wikilinks в тексте (R30)', () => {
    const md = 'Текст [[id-1|Первая]] и [[id-2|Вторая]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('сохраняет wikilink без label как [[id]] (R30)', () => {
    const md = 'См. [[id-1]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('восстанавливает вручную набранную [[ссылку]] без prepare', () => {
    expect(restoreWikiLinks('\\[\\[id|Заголовок\\]\\]\n')).toBe('[[id|Заголовок]]\n')
  })

  it('prepareMarkdownForEditor больше не токенизирует wikilinks (R30)', () => {
    const prepared = prepareMarkdownForEditor('[[id|A]]\n')
    expect(prepared.markdown).toBe('[[id|A]]\n')
    expect(prepared.wikiLinks).toEqual([])
  })

  it('команда setWikiLink вставляет wikilink и сериализует в markdown (R30)', () => {
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

    editor.commands.setWikiLink({ noteId: 'note-1', label: 'Заметка 1' })
    const md = toStoredMarkdown(editor.getMarkdown(), prepared.wikiLinks)
    editor.destroy()

    expect(md).toContain('[[note-1|Заметка 1]]')
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
