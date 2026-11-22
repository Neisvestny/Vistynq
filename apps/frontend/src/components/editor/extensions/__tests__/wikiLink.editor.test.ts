// @vitest-environment jsdom
/* eslint-disable @typescript-eslint/no-explicit-any -- TODO(R33): Tiptap node view/editor internals are untyped in tests */
import { afterEach, describe, expect, it } from 'vitest'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from '@tiptap/markdown'
import { mount } from '@vue/test-utils'
import { WikiLink } from '../WikiLink'
import WikiLinkChip from '../WikiLinkChip.vue'

const editors: Editor[] = []

function createEditor(content?: string): Editor {
  const editor = new Editor({
    extensions: [StarterKit, Markdown, WikiLink],
    content: content ?? '',
    contentType: content ? 'markdown' : undefined,
  })
  editors.push(editor)
  return editor
}

afterEach(() => {
  while (editors.length > 0) {
    editors.pop()?.destroy()
  }
})

describe('WikiLink editor integration', () => {
  it('parses wikilink markdown into wikiLink node', () => {
    const editor = createEditor('См. [[note-1|Заметка 1]]')
    const json = editor.getJSON()
    const paragraph = json.content?.[0]
    const wikiNode = paragraph?.content?.find((n: any) => n.type === 'wikiLink')
    expect(wikiNode).toBeTruthy()
    expect(wikiNode?.attrs).toMatchObject({ noteId: 'note-1', label: 'Заметка 1' })
  })

  it('parses wikilink without label', () => {
    const editor = createEditor('[[note-1]]')
    const wikiNode = editor.getJSON().content?.[0]?.content?.find((n: any) => n.type === 'wikiLink')
    expect(wikiNode?.attrs).toMatchObject({ noteId: 'note-1', label: 'note-1' })
  })

  it('round-trips markdown with multiple wikilinks', () => {
    const md = 'Текст [[id-1|Первая]] и [[id-2|Вторая]]\n'
    const editor = createEditor(md)
    expect(editor.getMarkdown()).toContain('[[id-1|Первая]]')
    expect(editor.getMarkdown()).toContain('[[id-2|Вторая]]')
  })

  it('setWikiLink command inserts node and serializes to markdown', () => {
    const editor = createEditor('')
    editor.commands.setWikiLink({ noteId: 'note-1', label: 'Заметка 1' })
    expect(editor.getMarkdown()).toContain('[[note-1|Заметка 1]]')
  })

  it('setWikiLink falls back label to noteId', () => {
    const editor = createEditor('')
    editor.commands.setWikiLink({ noteId: 'note-1', label: '' })
    expect(editor.getMarkdown()).toContain('[[note-1]]')
  })

  it('renders HTML with data attributes', () => {
    const editor = createEditor('[[note-1|Label]]')
    const html = editor.getHTML()
    expect(html).toContain('data-wikilink')
    expect(html).toContain('data-note-id="note-1"')
    expect(html).toContain('data-label="Label"')
  })

  it('parses HTML span[data-wikilink] back to wikiLink node', () => {
    const editor = createEditor('')
    editor.commands.setContent(
      '<p><span data-wikilink data-note-id="n1" data-label="L">[[L]]</span></p>',
      { contentType: 'html' }
    )
    const wikiNode = editor.getJSON().content?.[0]?.content?.find((n: any) => n.type === 'wikiLink')
    expect(wikiNode?.attrs).toMatchObject({ noteId: 'n1', label: 'L' })
  })
})

describe('WikiLinkChip component', () => {
  function mountChip(attrs: Record<string, unknown>, selected = false) {
    return mount(WikiLinkChip, {
      props: {
        node: { attrs } as any,
        selected,
        editor: {} as any,
        getPos: () => 0,
        decorations: [],
        extension: {} as any,
        HTMLAttributes: {},
        innerDecorations: { find: () => [] } as any,
        updateAttributes: () => undefined,
        deleteNode: () => undefined,
      } as any,
      global: {
        stubs: {
          NodeViewWrapper: {
            template: '<span class="node-view-wrapper"><slot /></span>',
          },
        },
      },
    })
  }

  it('renders label from attrs', () => {
    const wrapper = mountChip({ noteId: 'n1', label: 'My Note' })
    expect(wrapper.text()).toContain('My Note')
  })

  it('falls back to noteId then to default label', () => {
    expect(mountChip({ noteId: 'n1', label: '' }).text()).toContain('n1')
    expect(mountChip({ noteId: '', label: '' }).text()).toContain('ссылка')
  })
})
