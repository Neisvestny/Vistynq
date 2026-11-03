<script lang="ts">
/** Данные ноды, которые маппит CanvasView из GraphNode (api/types.ts). */
export interface NoteNodeData {
  title: string
  importance: number
  tags: string[]
  tagColors: Record<string, string>
}
</script>

<script setup lang="ts">
/**
 * Нода заметки на канвасе (docs/ui-ux.md §2 «Ноды»):
 * прямоугольник ~220px, только заголовок (1–2 строки), обводка по важности,
 * чипы тегов. Read-only (R14) — drag/resize/handles приходят с R26.
 */
defineOptions({ inheritAttrs: false })

// Слот #node-note передаёт полный NodeProps — берём только data.
const props = defineProps<{ data: NoteNodeData }>()

const importance = () => Math.min(5, Math.max(1, props.data.importance))
</script>

<template>
  <div
    class="note-node"
    :style="{ borderColor: `var(--importance-${importance()})` }"
    :title="props.data.title"
  >
    <p class="note-node__title">{{ props.data.title || 'Без названия' }}</p>

    <div v-if="props.data.tags.length" class="note-node__tags">
      <span
        v-for="tag in props.data.tags"
        :key="tag"
        class="note-node__tag"
        :style="props.data.tagColors[tag] ? { borderColor: props.data.tagColors[tag] } : {}"
      >
        {{ tag }}
      </span>
    </div>
  </div>
</template>
