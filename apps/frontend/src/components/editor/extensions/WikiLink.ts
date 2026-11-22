import { mergeAttributes, Node } from '@tiptap/core'
import { tGlobal } from '@/i18n'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import WikiLinkChip from './WikiLinkChip.vue'

export interface WikiLinkAttrs {
  noteId: string
  label: string
}

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    wikiLink: {
      setWikiLink: (attrs: WikiLinkAttrs) => ReturnType
    }
  }
}

const WIKI_LINK_INPUT_RE = /^\[\[([^[\]|]+)(?:\|([^\]]+))?\]\]$/

export const WikiLink = Node.create({
  name: 'wikiLink',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,

  addAttributes() {
    return {
      noteId: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-note-id') ?? '',
        renderHTML: (attributes) => ({ 'data-note-id': attributes.noteId }),
      },
      label: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-label') ?? element.textContent ?? '',
        renderHTML: (attributes) => ({ 'data-label': attributes.label }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'span[data-wikilink]' }]
  },

  renderHTML({ HTMLAttributes, node }) {
    const label = String(node.attrs.label || node.attrs.noteId || tGlobal('links.linkFallback'))
    return [
      'span',
      mergeAttributes(HTMLAttributes, {
        'data-wikilink': '',
        class: 'wiki-link-chip',
      }),
      `[[${label}]]`,
    ]
  },

  addNodeView() {
    return VueNodeViewRenderer(WikiLinkChip)
  },

  addCommands() {
    return {
      setWikiLink:
        (attrs) =>
        ({ commands }) =>
          commands.insertContent({
            type: this.name,
            attrs: {
              noteId: attrs.noteId,
              label: attrs.label || attrs.noteId,
            },
          }),
    }
  },

  // @tiptap/markdown integration (v3)
  markdownTokenizer: {
    name: 'wikiLink',
    level: 'inline',
    start: (src: string) => src.indexOf('[['),
    tokenize: (src: string) => {
      const match = /^\[\[([^[\]|]+)(?:\|([^\]]+))?\]\]/.exec(src)
      if (!match) return undefined
      const rawTarget = match[1]?.trim() ?? ''
      if (!rawTarget) return undefined
      const rawLabel = match[2]?.trim() || rawTarget
      return {
        type: 'wikiLink',
        raw: match[0],
        noteId: rawTarget,
        label: rawLabel,
      }
    },
  },

  parseMarkdown: (token: Record<string, unknown>) => {
    const noteId = String(token.noteId ?? token.rawTarget ?? '').trim()
    const label = String(token.label ?? noteId).trim()
    if (!noteId) return []
    return {
      type: 'wikiLink',
      attrs: {
        noteId,
        label: label || noteId,
      },
    }
  },

  renderMarkdown: (node: Record<string, unknown>) => {
    const attrs = (node.attrs ?? {}) as Record<string, unknown>
    const noteId = String(attrs.noteId ?? '').trim()
    const label = String(attrs.label ?? noteId).trim()
    if (!noteId) return ''
    if (!label || label === noteId) return `[[${noteId}]]`
    return `[[${noteId}|${label}]]`
  },
})

export function parseWikiLinkText(text: string): WikiLinkAttrs | null {
  const match = WIKI_LINK_INPUT_RE.exec(text.trim())
  if (!match) return null
  const noteId = match[1]?.trim() ?? ''
  if (!noteId) return null
  const label = match[2]?.trim() || noteId
  return { noteId, label }
}
