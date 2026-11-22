<script setup lang="ts">
/**
 * Инлайн-чип wikilink (R30): [[uuid|Title]].
 * Не навигирует при клике; contenteditable=false.
 */
import { NodeViewWrapper } from '@tiptap/vue-3'
import type { NodeViewProps } from '@tiptap/vue-3'
import { computed } from 'vue'

const props = defineProps<NodeViewProps>()

const label = computed(() => {
  const raw = props.node.attrs.label
  if (typeof raw === 'string' && raw.trim().length > 0) return raw
  const id = props.node.attrs.noteId
  return typeof id === 'string' && id.trim().length > 0 ? id : 'ссылка'
})
</script>

<template>
  <NodeViewWrapper as="span" class="wiki-link-chip" contenteditable="false">
    <span class="wiki-link-chip__icon" aria-hidden="true">🔗</span>
    <span class="wiki-link-chip__label">{{ label }}</span>
  </NodeViewWrapper>
</template>

<style scoped>
.wiki-link-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  max-width: 100%;
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: var(--color-accent-soft, rgba(59, 130, 246, 0.08));
  color: var(--color-accent);
  font-size: 0.9em;
  line-height: 1.4;
  vertical-align: baseline;
  white-space: nowrap;
  user-select: none;
}

.wiki-link-chip__icon {
  font-size: 0.8em;
  line-height: 1;
}

.wiki-link-chip__label {
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 16rem;
}
</style>
