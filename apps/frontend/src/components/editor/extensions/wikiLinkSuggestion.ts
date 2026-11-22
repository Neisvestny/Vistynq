import { Extension } from '@tiptap/core'
import { VueRenderer } from '@tiptap/vue-3'
import Suggestion, { type SuggestionProps } from '@tiptap/suggestion'
import type { Editor } from '@tiptap/core'
import WikiLinkList, { type WikiLinkItem } from '@/components/editor/WikiLinkList.vue'

export interface WikiLinkSuggestionOptions {
  /** Vozvrashchaet kandidaty zametok dlya avtodopolneniya. */
  getItems: (query: string) => WikiLinkItem[]
}

type SuggestionRenderer = {
  onStart: (props: SuggestionProps<WikiLinkItem>) => void
  onUpdate: (props: SuggestionProps<WikiLinkItem>) => void
  onKeyDown: (props: { event: KeyboardEvent }) => boolean
  onExit: (props: SuggestionProps<WikiLinkItem>) => void
}

function renderSuggestion(): SuggestionRenderer {
  let component: VueRenderer | null = null
  let cleanup: (() => void) | null = null
  let container: HTMLDivElement | null = null
  let manualPositioning = false

  const updateManualPosition = (props: SuggestionProps<WikiLinkItem>) => {
    const rect = props.clientRect?.()
    if (!rect || !container) return
    container.style.left = `${rect.left}px`
    container.style.top = `${rect.bottom + 4}px`
  }

  const destroy = () => {
    cleanup?.()
    cleanup = null
    component?.destroy()
    component = null
    container?.remove()
    container = null
    manualPositioning = false
  }

  return {
    onStart(props) {
      if (!props.clientRect) return

      component = new VueRenderer(WikiLinkList, {
        props,
        editor: props.editor,
      })

      container = document.createElement('div')
      container.style.zIndex = '1200'
      container.appendChild(component.element as HTMLElement)

      const maybeMount = (
        props as unknown as { mount?: (el: HTMLElement, opts?: unknown) => () => void }
      ).mount

      if (typeof maybeMount === 'function') {
        // Managed positioning: mount appends and positions the element.
        cleanup = maybeMount(container)
      } else {
        manualPositioning = true
        container.style.position = 'fixed'
        container.style.left = '0'
        container.style.top = '0'
        document.body.appendChild(container)
        updateManualPosition(props)
        const onScroll = () => updateManualPosition(props)
        window.addEventListener('scroll', onScroll, true)
        window.addEventListener('resize', onScroll)
        cleanup = () => {
          window.removeEventListener('scroll', onScroll, true)
          window.removeEventListener('resize', onScroll)
        }
      }
    },
    onUpdate(props) {
      component?.updateProps(props)
      if (manualPositioning) updateManualPosition(props)
    },
    onKeyDown(props) {
      if (props.event.key === 'Escape') {
        destroy()
        return true
      }
      const ref = component?.ref as { onKeyDown?: (event: KeyboardEvent) => boolean } | undefined
      return ref?.onKeyDown?.(props.event) ?? false
    },
    onExit() {
      destroy()
    },
  }
}

export const WikiLinkSuggestion = Extension.create<WikiLinkSuggestionOptions>({
  name: 'wikiLinkSuggestion',

  addOptions() {
    return {
      getItems: () => [],
    }
  },

  addProseMirrorPlugins() {
    const getItems = this.options.getItems

    return [
      Suggestion<WikiLinkItem>({
        editor: this.editor,
        char: '[',
        allowSpaces: true,
        startOfLine: false,
        allow: ({ state, range }) =>
          state.doc.textBetween(range.from, range.from + 2, '\n', '\0') === '[[',
        items: ({ query }) => {
          // char '[' => query posle triggera: "[foo" dlya vvoda "[[foo"
          if (!query.startsWith('[')) return []
          const search = query.slice(1).trim().toLowerCase()
          const items = getItems(search)
          if (!search) return items.slice(0, 20)
          return items
            .filter(
              (item) =>
                item.title.toLowerCase().includes(search) || item.id.toLowerCase().includes(search)
            )
            .slice(0, 20)
        },
        command: ({ editor, range, props }) => {
          insertWikiLink(editor, range, props)
        },
        render: renderSuggestion,
      }),
    ]
  },
})

function insertWikiLink(editor: Editor, range: { from: number; to: number }, item: WikiLinkItem) {
  editor
    .chain()
    .focus()
    .insertContentAt(range, {
      type: 'wikiLink',
      attrs: {
        noteId: item.id,
        label: item.title || item.id,
      },
    })
    .run()
}
