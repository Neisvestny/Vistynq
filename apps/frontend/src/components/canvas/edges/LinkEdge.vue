<script setup lang="ts">
import { BaseEdge, EdgeLabelRenderer, getBezierPath, type EdgeProps } from '@vue-flow/core'
import { computed } from 'vue'
import type { LinkEdgeData } from '@/composables/useGraph'

const props = defineProps<EdgeProps<LinkEdgeData>>()

const path = computed(() =>
  getBezierPath({
    sourceX: props.sourceX,
    sourceY: props.sourceY,
    sourcePosition: props.sourcePosition,
    targetX: props.targetX,
    targetY: props.targetY,
    targetPosition: props.targetPosition,
  })
)

const edgePath = computed(() => path.value[0])
const labelX = computed(() => path.value[1])
const labelY = computed(() => path.value[2])

const isDangling = computed(() => props.data?.dangling === true)
const showLabel = computed(() => isDangling.value)
</script>

<template>
  <BaseEdge :id="id" :path="edgePath" :marker-end="markerEnd" :label="undefined" :style="style" />

  <!-- Обрыв на конце висячей связи (purged target). -->
  <g v-if="isDangling">
    <line
      :x1="targetX - 6"
      :y1="targetY - 6"
      :x2="targetX + 6"
      :y2="targetY + 6"
      class="link-edge__break"
    />
    <line
      :x1="targetX - 6"
      :y1="targetY + 6"
      :x2="targetX + 6"
      :y2="targetY - 6"
      class="link-edge__break"
    />
  </g>

  <EdgeLabelRenderer v-if="showLabel">
    <div
      class="link-edge__label nodrag nopan"
      :style="{
        position: 'absolute',
        transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
        pointerEvents: 'all',
      }"
    >
      ссылка удалена
    </div>
  </EdgeLabelRenderer>
</template>

<style scoped>
.link-edge__break {
  stroke: var(--color-text-faint, #94a3b8);
  stroke-width: 1.5;
  stroke-linecap: round;
}

.link-edge__label {
  padding: 0.1rem 0.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-surface-raised, #fff) 92%, transparent);
  border: 1px dashed var(--color-border);
  color: var(--color-text-muted);
  font-size: 10px;
  line-height: 1.2;
  white-space: nowrap;
}
</style>
