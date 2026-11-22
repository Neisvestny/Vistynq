<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'
import type { GhostNodeData } from '@/composables/useGraph'

defineProps<{
  id: string
  data: GhostNodeData
}>()

const emit = defineEmits<{
  restore: [noteId: string]
}>()
</script>

<template>
  <div class="ghost-node" role="group" aria-label="Удалённая заметка">
    <Handle type="target" :position="Position.Left" class="ghost-node__handle" />

    <p class="ghost-node__title">{{ data.label || 'ссылка удалена' }}</p>
    <p class="ghost-node__hint">Заметка удалена</p>

    <button
      v-if="data.canRestore"
      type="button"
      class="ghost-node__restore nodrag nopan"
      @click.stop="emit('restore', data.targetNoteId)"
    >
      Восстановить
    </button>
  </div>
</template>

<style scoped>
.ghost-node {
  width: 220px;
  padding: 0.7rem 0.75rem;
  border: 1.5px dashed var(--color-border-strong, var(--color-border));
  border-radius: var(--radius-md, 10px);
  background: color-mix(in srgb, var(--color-surface-raised, #fff) 92%, transparent);
  color: var(--color-text-muted);
  box-shadow: none;
}

.ghost-node__title {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-text-muted);
  word-break: break-word;
}

.ghost-node__hint {
  margin: 0.2rem 0 0;
  font-size: 0.75rem;
  color: var(--color-text-faint, var(--color-text-muted));
}

.ghost-node__restore {
  margin-top: 0.55rem;
  padding: 0.25rem 0.55rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.78rem;
  cursor: pointer;
}

.ghost-node__restore:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.ghost-node__handle {
  opacity: 0;
  pointer-events: none;
}
</style>
