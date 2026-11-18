<script setup lang="ts">
import { VueFlow } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import NoteNode from '@/components/canvas/NoteNode.vue'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'

defineProps<{
  nodes: Node<NoteNodeData>[]
  edges: Edge[]
}>()
</script>

<template>
  <div class="canvas-board">
    <VueFlow
      :nodes="nodes"
      :edges="edges"
      fit-view-on-init
      :min-zoom="0.2"
      :max-zoom="2"
      :nodes-draggable="false"
      :nodes-connectable="false"
      :elements-selectable="false"
      :edges-focusable="false"
      :nodes-focusable="false"
      :zoom-on-double-click="false"
      :zoom-on-pinch="true"
      :pan-on-scroll="true"
      :zoom-on-scroll="false"
      zoom-activation-key-code="Control"
      :pan-on-drag="true"
      pan-activation-key-code="Space"
      :prevent-scrolling="true"
    >
      <template #node-note="nodeProps">
        <NoteNode :data="nodeProps.data" />
      </template>

      <Background pattern-color="#cdd4e3" :gap="24" />
      <Controls :show-interactive="false" />
    </VueFlow>

    <slot />
  </div>
</template>

<style scoped>
.canvas-board {
  position: relative;
  min-height: 0;
  height: 100%;
  width: 100%;
}

.canvas-board :deep(.vue-flow) {
  background: var(--color-surface-subtle);
}

.canvas-board :deep(.vue-flow__edge-path) {
  stroke: var(--color-text-soft);
  stroke-width: 2;
}

.canvas-board :deep(.canvas-edge--indirect .vue-flow__edge-path) {
  stroke: var(--color-text-faint);
}

.canvas-board :deep(.vue-flow__edge-textbg),
.canvas-board :deep(.vue-flow__edge-text) {
  fill: var(--color-surface-raised);
  font-size: 11px;
}

.canvas-board :deep(.vue-flow__controls) {
  box-shadow: var(--shadow-soft);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.canvas-board :deep(.vue-flow__controls-button) {
  background: var(--color-surface-raised);
  border-bottom: 1px solid var(--color-border-subtle);
  fill: var(--color-text-muted);
}

.canvas-board :deep(.vue-flow__controls-button:hover) {
  background: var(--color-accent-soft);
}
</style>
