// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { Editor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from '@tiptap/markdown'

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

  it('не экранирует [[wikilinks]] при round-trip', () => {
    const md = 'См. [[123e4567-e89b-12d3-a456-426614174000|Заметка Б]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('поддерживает несколько wikilinks и токены, встречающиеся в тексте', () => {
    const md = 'vistynqwikilink0zq [[id-1|Первая]] и [[id-2|Вторая]]\n'
    expect(roundTrip(md)).toBe(md)
  })

  it('восстанавливает вручную набранную [[ссылку]] без prepare', () => {
    expect(restoreWikiLinks('\\[\\[id|Заголовок\\]\\]\n')).toBe('[[id|Заголовок]]\n')
  })

  it('готовый markdown грузится в редактор без токенов', () => {
    const prepared = prepareMarkdownForEditor('[[id|A]]\n')
    expect(prepared.markdown).not.toContain('[[')
    expect(prepared.wikiLinks).toEqual([{ token: 'vistynqwikilink0zq', link: '[[id|A]]' }])
  })
})
