<script setup lang="ts">
/**
 * Tiptap-редактор поверх markdown-тела заметки (R28).
 * Наружу отдаёт markdown (не HTML): сервер хранит именно его и сам парсит
 * [[wikilinks]]. Хелперы prepare/restore защищают wikilinks от экранирования.
 */
import { ref, watch } from 'vue'
import { EditorContent, useEditor, type Editor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from '@tiptap/markdown'

import EditorToolbar from '@/components/editor/EditorToolbar.vue'
import {
  prepareMarkdownForEditor,
  toStoredMarkdown,
  type WikiLinkToken,
} from '@/lib/editorMarkdown'

const props = withDefaults(
  defineProps<{
    modelValue: string
    editable?: boolean
  }>(),
  { editable: true }
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  ready: [editor: Editor]
}>()

const prepared = prepareMarkdownForEditor(props.modelValue)
const wikiLinks = ref<WikiLinkToken[]>(prepared.wikiLinks)
let lastValue = props.modelValue

const editor = useEditor({
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
  editable: props.editable,
  editorProps: {
    attributes: {
      class: 'note-editor__prose',
      'aria-label': 'Текст заметки',
    },
  },
  onUpdate: ({ editor: instance }) => {
    const markdown = toStoredMarkdown(instance.getMarkdown(), wikiLinks.value)
    if (markdown === lastValue) return
    lastValue = markdown
    emit('update:modelValue', markdown)
  },
})

watch(
  editor,
  (instance) => {
    if (instance) emit('ready', instance)
  },
  { immediate: true, flush: 'post' }
)

watch(
  () => props.editable,
  (value) => {
    editor.value?.setEditable(value, false)
  }
)

watch(
  () => props.modelValue,
  (value) => {
    const instance = editor.value
    if (!instance || value === lastValue) return
    const next = prepareMarkdownForEditor(value)
    wikiLinks.value = next.wikiLinks
    lastValue = value
    instance.commands.setContent(next.markdown, {
      contentType: 'markdown',
      emitUpdate: false,
    })
  }
)
</script>

<template>
  <div class="markdown-editor" :class="{ 'markdown-editor--readonly': !editable }">
    <EditorToolbar v-if="editable" :editor="editor" />
    <EditorContent class="markdown-editor__content" :editor="editor" />
    <p v-if="!editable" class="markdown-editor__readonly">Только чтение</p>
  </div>
</template>

<style scoped>
.markdown-editor {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 10px);
  background: var(--color-surface);
  overflow: hidden;
}

.markdown-editor--readonly {
  background: transparent;
}

.markdown-editor__content {
  min-height: 260px;
  max-height: 60vh;
  overflow: auto;
  padding: 0.9rem 1rem;
}

.markdown-editor__readonly {
  margin: 0;
  padding: 0 1rem 0.75rem;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.markdown-editor :deep(.note-editor__prose) {
  min-height: 240px;
  outline: none;
  color: var(--color-text);
  line-height: 1.55;
  font-size: 0.9375rem;
}

.markdown-editor :deep(.note-editor__prose > * + *) {
  margin-top: 0.7em;
}

.markdown-editor :deep(.note-editor__prose h1),
.markdown-editor :deep(.note-editor__prose h2),
.markdown-editor :deep(.note-editor__prose h3) {
  color: var(--color-text);
  line-height: 1.25;
}

.markdown-editor :deep(.note-editor__prose h1) {
  font-size: 1.5rem;
}

.markdown-editor :deep(.note-editor__prose h2) {
  font-size: 1.25rem;
}

.markdown-editor :deep(.note-editor__prose h3) {
  font-size: 1.1rem;
}

.markdown-editor :deep(.note-editor__prose ul),
.markdown-editor :deep(.note-editor__prose ol) {
  padding-left: 1.4rem;
}

.markdown-editor :deep(.note-editor__prose ul) {
  list-style: disc;
}

.markdown-editor :deep(.note-editor__prose ol) {
  list-style: decimal;
}

.markdown-editor :deep(.note-editor__prose code) {
  padding: 0.1em 0.35em;
  border-radius: 4px;
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.05));
  font-family: var(--font-mono, monospace);
  font-size: 0.875em;
}

.markdown-editor :deep(.note-editor__prose pre) {
  padding: 0.75rem;
  overflow-x: auto;
  border-radius: 6px;
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.05));
}

.markdown-editor :deep(.note-editor__prose pre code) {
  padding: 0;
  background: transparent;
}

.markdown-editor :deep(.note-editor__prose a) {
  color: var(--color-accent);
  text-decoration: underline;
}
</style>
