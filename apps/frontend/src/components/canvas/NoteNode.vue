<script lang="ts">
import type { NoteVisibility } from '@/api/types'

/** Node data mapped by useGraph from GraphNode (api/types.ts). */
export interface NoteNodeData {
  title: string
  importance: number
  tags: string[]
  tagColors: Record<string, string>
  visibility?: NoteVisibility
  authorId?: string
  isRelevant?: boolean
  archived?: boolean
  dimmed?: boolean
  canMove?: boolean
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { NodeResizeControl } from '@vue-flow/node-resizer'
import { Lock } from 'lucide-vue-next'

/**
 * Canvas note node (docs/ui-ux.md §2 "Nodes"):
 * ~220px rectangle, title, importance border, tag chips.
 * Drag/resize by permissions - R26; tag highlight/dim - R27.
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
  focus: [id: string]
  blur: [id: string]
  hover: [id: string | null]
  delete: [id: string]
}>()

const { t } = useI18n()
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
      'note-node--archived': data.archived === true,
    }"
    :style="{ borderColor: `var(--importance-${importance})` }"
    :title="data.title"
    role="button"
    tabindex="0"
    :aria-label="t('note.openAria', { title: data.title || t('note.untitled') })"
    @dblclick.stop="emit('open', id)"
    @keydown.enter.stop="emit('open', id)"
    @keydown.delete.stop="emit('delete', id)"
    @focus="emit('focus', id)"
    @blur="emit('blur', id)"
    @mouseenter="emit('hover', id)"
    @mouseleave="emit('hover', null)"
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

    <p class="note-node__title">{{ data.title || t('note.untitled') }}</p>

    <span class="note-node__badges">
      <span
        v-if="data.visibility === 'private'"
        class="note-node__lock"
        :aria-label="t('note.private')"
        :title="t('note.private')"
      >
        <Lock :size="13" aria-hidden="true" />
      </span>
      <span v-if="data.archived" class="note-node__archive-badge">
        {{ t('note.archivedBadge') }}
      </span>
    </span>

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
