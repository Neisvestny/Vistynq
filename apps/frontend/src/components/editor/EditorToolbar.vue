<script setup lang="ts">
/**
 * Tiptap editor formatting toolbar (R28).
 * H1-H3, bold/italic, lists, code, link, undo/redo.
 * Image upload and [[ autocomplete arrive in R30/R31.
 */
import type { Editor } from '@tiptap/vue-3'
import { useI18n } from 'vue-i18n'
import { notify } from '@/api/notify'

const props = withDefaults(
  defineProps<{
    editor?: Editor
    canUploadImage?: boolean
  }>(),
  { editor: undefined, canUploadImage: false }
)

const emit = defineEmits<{
  'pick-image': []
}>()

const { t } = useI18n()
const headingLevels = [1, 2, 3] as const

function isActive(name: string, attrs?: Record<string, unknown>): boolean {
  return props.editor?.isActive(name, attrs) ?? false
}

function setHeading(level: 1 | 2 | 3): void {
  void props.editor?.chain().focus().toggleHeading({ level }).run()
}

function setLink(): void {
  const editor = props.editor
  if (!editor) return
  const current = editor.getAttributes('link').href as string | undefined
  const answer = window.prompt(t('editor.linkPrompt'), current ?? 'https://')
  if (answer === null) return

  const href = answer.trim()
  if (href === '') {
    void editor.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }
  if (!/^(https?:\/\/|mailto:)/i.test(href)) {
    notify({
      title: t('editor.invalidLink'),
      description: t('editor.invalidLinkDescription'),
      status: 'error',
    })
    return
  }
  void editor.chain().focus().extendMarkRange('link').setLink({ href }).run()
}
</script>

<template>
  <div class="editor-toolbar" role="toolbar" :aria-label="t('editor.toolbarAria')">
    <div class="editor-toolbar__group">
      <button
        v-for="level in headingLevels"
        :key="level"
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('heading', { level }) }"
        :title="t('editor.heading', { level })"
        :disabled="!editor"
        @click="setHeading(level)"
      >
        H{{ level }}
      </button>
    </div>

    <span class="editor-toolbar__separator" />

    <div class="editor-toolbar__group">
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('bold') }"
        :title="t('editor.bold')"
        :disabled="!editor"
        @click="void editor?.chain().focus().toggleBold().run()"
      >
        <strong>B</strong>
      </button>
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('italic') }"
        :title="t('editor.italic')"
        :disabled="!editor"
        @click="void editor?.chain().focus().toggleItalic().run()"
      >
        <em>I</em>
      </button>
    </div>

    <span class="editor-toolbar__separator" />

    <div class="editor-toolbar__group">
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('bulletList') }"
        :title="t('editor.bulletList')"
        :disabled="!editor"
        @click="void editor?.chain().focus().toggleBulletList().run()"
      >
        {{ t('editor.bulletListLabel') }}
      </button>
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('orderedList') }"
        :title="t('editor.orderedList')"
        :disabled="!editor"
        @click="void editor?.chain().focus().toggleOrderedList().run()"
      >
        {{ t('editor.orderedListLabel') }}
      </button>
    </div>

    <span class="editor-toolbar__separator" />

    <div class="editor-toolbar__group">
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('code') }"
        :title="t('editor.inlineCode')"
        :disabled="!editor"
        @click="void editor?.chain().focus().toggleCode().run()"
      >
        &lt;/&gt;
      </button>
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('codeBlock') }"
        :title="t('editor.codeBlock')"
        :disabled="!editor"
        @click="void editor?.chain().focus().toggleCodeBlock().run()"
      >
        { }
      </button>
      <button
        type="button"
        class="editor-toolbar__button"
        :class="{ 'editor-toolbar__button--active': isActive('link') }"
        :title="t('editor.link')"
        :disabled="!editor"
        @click="setLink"
      >
        🔗
      </button>
      <button
        type="button"
        class="editor-toolbar__button"
        :title="canUploadImage ? t('editor.insertImage') : t('editor.imageUploadUnavailable')"
        :disabled="!editor || !canUploadImage"
        @click="emit('pick-image')"
      >
        🖼
      </button>
    </div>

    <span class="editor-toolbar__separator" />

    <div class="editor-toolbar__group">
      <button
        type="button"
        class="editor-toolbar__button"
        :title="t('editor.undo')"
        :disabled="!editor || !editor.can().undo()"
        @click="void editor?.chain().focus().undo().run()"
      >
        ↶
      </button>
      <button
        type="button"
        class="editor-toolbar__button"
        :title="t('editor.redo')"
        :disabled="!editor || !editor.can().redo()"
        @click="void editor?.chain().focus().redo().run()"
      >
        ↷
      </button>
    </div>
  </div>
</template>

<style scoped>
.editor-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem;
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-alt, var(--color-surface));
}

.editor-toolbar__group {
  display: inline-flex;
  gap: 0.2rem;
}

.editor-toolbar__separator {
  width: 1px;
  height: 1.25rem;
  background: var(--color-border);
}

.editor-toolbar__button {
  min-width: 1.9rem;
  padding: 0.25rem 0.4rem;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  font-size: 0.8rem;
  line-height: 1.2;
  cursor: pointer;
}

.editor-toolbar__button:hover:not(:disabled) {
  border-color: var(--color-border);
  background: var(--color-surface);
}

.editor-toolbar__button--active {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.editor-toolbar__button:disabled {
  opacity: 0.4;
  cursor: default;
}
</style>
