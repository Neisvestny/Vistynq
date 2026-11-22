<script lang="ts">
import type { NoteVisibility } from '@/api/types'

/** Данные ноды, которые маппит useGraph из GraphNode (api/types.ts). */
export interface NoteNodeData {
  title: string
  importance: number
  tags: string[]
  tagColors: Record<string, string>
  visibility?: NoteVisibility
  authorId?: string
  isRelevant?: boolean
  dimmed?: boolean
  canMove?: boolean
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { NodeResizeControl } from '@vue-flow/node-resizer'

/**
 * Нода заметки на канвасе (docs/ui-ux.md §2 «Ноды»):
 * прямоугольник ~220px, заголовок, обводка по важности, чипы тегов.
 * Drag/resize по правам — R26; подсветка/dim по тегу — R27.
 */
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    id: string
    data: NoteNodeData
    selected?: boolean
    draggable?: boolean
  }>(),
  {
    selected: false,
    draggable: false,
  }
)

const emit = defineEmits<{
  'resize-end': [payload: { id: string; width: number; height?: number; x?: number; y?: number }]
  open: [id: string]
}>()

const importance = computed(() => Math.min(5, Math.max(1, props.data.importance)))
const canResize = computed(() => props.draggable || props.data.canMove === true)

function onResizeEnd(event: { params: { width: number; height: number; x: number; y: number } }) {
  const width = Math.min(800, Math.max(120, Math.round(event.params.width)))
  emit('resize-end', {
    id: props.id,
    width,
    height: event.params.height,
    x: event.params.x,
    y: event.params.y,
  })
}
</script>

<template>
  <div
    class="note-node"
    :class="{
      'note-node--selected': selected,
      'note-node--dimmed': data.dimmed,
      'note-node--movable': canResize,
    }"
    :style="{ borderColor: `var(--importance-${importance})` }"
    :title="data.title"
    role="button"
    tabindex="0"
    :aria-label="`Открыть заметку: ${data.title}`"
    @dblclick.stop="emit('open', id)"
    @keydown.enter.stop="emit('open', id)"
  >
    <NodeResizeControl
      v-if="canResize"
      position="right"
      :min-width="120"
      :max-width="800"
      :min-height="40"
      :max-height="800"
      @resize-end="onResizeEnd"
    />
    <NodeResizeControl
      v-if="canResize"
      position="bottom-right"
      :min-width="120"
      :max-width="800"
      :min-height="40"
      :max-height="800"
      @resize-end="onResizeEnd"
    />

    <p class="note-node__title">{{ data.title || 'Без названия' }}</p>

    <div v-if="data.tags.length" class="note-node__tags">
      <span
        v-for="tag in data.tags"
        :key="tag"
        class="note-node__tag"
        :style="data.tagColors[tag] ? { borderColor: data.tagColors[tag] } : {}"
      >
        {{ tag }}
      </span>
    </div>
  </div>
</template>
