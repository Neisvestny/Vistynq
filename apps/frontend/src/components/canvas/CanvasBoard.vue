<script setup lang="ts">
import { computed } from 'vue'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import type {
  Edge,
  EdgeMouseEvent,
  Node,
  NodeChange,
  NodeDragEvent,
  NodeMouseEvent,
  XYPosition,
} from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/node-resizer/dist/style.css'
import NoteNode from '@/components/canvas/NoteNode.vue'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'
import GhostNote from '@/components/canvas/GhostNote.vue'
import DanglingAnchor from '@/components/canvas/DanglingAnchor.vue'
import LinkEdge from '@/components/canvas/edges/LinkEdge.vue'
import type { GhostNodeData } from '@/composables/useGraph'

const props = withDefaults(
  defineProps<{
    nodes: Node[]
    edges: Edge[]
    layoutEditable?: boolean
    edgesSelectable?: boolean
    linking?: boolean
  }>(),
  {
    layoutEditable: false,
    edgesSelectable: false,
    linking: false,
  }
)

const emit = defineEmits<{
  'nodes-change': [changes: NodeChange[]]
  'node-drag-start': [payload: { id: string; position: XYPosition }]
  'node-drag': [payload: { id: string; position: XYPosition }]
  'node-drag-stop': [payload: { id: string; position: XYPosition }]
  'node-resize-end': [
    payload: { id: string; width: number; height?: number; x?: number; y?: number },
  ]
  'pane-context-menu': [payload: { event: MouseEvent; flowPosition: XYPosition }]
  'pane-click': [payload: { event: MouseEvent; flowPosition: XYPosition }]
  'node-context-menu': [payload: { event: MouseEvent; nodeId: string }]
  'node-click': [payload: { event: MouseEvent; nodeId: string }]
  'node-open': [nodeId: string]
  'edge-click': [payload: { event: MouseEvent; edgeId: string }]
  'edge-context-menu': [payload: { event: MouseEvent; edgeId: string }]
  'ghost-restore': [noteId: string]
  'node-focus': [nodeId: string]
  'node-hover': [nodeId: string | null]
  'node-delete': [nodeId: string]
  'edge-select': [edgeId: string]
  'edge-delete': [edgeId: string]
}>()

const { fitView, project, screenToFlowCoordinate } = useVueFlow()

const edgeTypes = { linkEdge: LinkEdge }

const flowNodes = computed(() => props.nodes)

function onNodesChange(changes: NodeChange[]) {
  emit('nodes-change', changes)
}

function onNodeDragStart(event: NodeDragEvent) {
  emit('node-drag-start', { id: event.node.id, position: { ...event.node.position } })
}

function onNodeDrag(event: NodeDragEvent) {
  emit('node-drag', { id: event.node.id, position: { ...event.node.position } })
}

function onNodeDragStop(event: NodeDragEvent) {
  emit('node-drag-stop', { id: event.node.id, position: { ...event.node.position } })
}

function onNodeResizeEnd(payload: {
  id: string
  width: number
  height?: number
  x?: number
  y?: number
}) {
  emit('node-resize-end', payload)
}

function resolveFlowPosition(event: MouseEvent): XYPosition {
  if (typeof screenToFlowCoordinate === 'function') {
    return screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
  }
  if (typeof project === 'function') {
    return project({ x: event.clientX, y: event.clientY })
  }
  return { x: event.clientX, y: event.clientY }
}

function onPaneContextMenu(event: MouseEvent) {
  event.preventDefault()
  emit('pane-context-menu', { event, flowPosition: resolveFlowPosition(event) })
}

function onPaneClick(event: MouseEvent) {
  emit('pane-click', { event, flowPosition: resolveFlowPosition(event) })
}

function onNodeContextMenu(event: NodeMouseEvent) {
  const mouse = event.event as MouseEvent | undefined
  if (mouse && typeof mouse.preventDefault === 'function') mouse.preventDefault()
  if (!mouse || !('clientX' in mouse)) return
  emit('node-context-menu', { event: mouse, nodeId: event.node.id })
}

function onNodeClick(event: NodeMouseEvent) {
  const mouse = event.event as MouseEvent | undefined
  if (!mouse || !('clientX' in mouse)) return
  emit('node-click', { event: mouse, nodeId: event.node.id })
}

function onEdgeClick(event: EdgeMouseEvent) {
  const mouse = event.event as MouseEvent | undefined
  if (!mouse || !('clientX' in mouse)) return
  emit('edge-click', { event: mouse, edgeId: event.edge.id })
}

function onEdgeContextMenu(event: EdgeMouseEvent) {
  const mouse = event.event as MouseEvent | undefined
  if (mouse && typeof mouse.preventDefault === 'function') mouse.preventDefault()
  if (!mouse || !('clientX' in mouse)) return
  emit('edge-context-menu', { event: mouse, edgeId: event.edge.id })
}

function focusNode(
  nodeId: string,
  options?: { duration?: number; padding?: number; maxZoom?: number }
) {
  void fitView({
    nodes: [nodeId],
    duration: options?.duration ?? 400,
    padding: options?.padding ?? 0.6,
    maxZoom: options?.maxZoom ?? 1.4,
  })
}

function fitAll(options?: { duration?: number; padding?: number }) {
  void fitView({ duration: options?.duration ?? 300, padding: options?.padding ?? 0.2 })
}

defineExpose({
  focusNode,
  fitAll,
  project,
  screenToFlowCoordinate,
})
</script>

<template>
  <div class="canvas-board" :class="{ 'canvas-board--linking': linking }">
    <VueFlow
      :nodes="flowNodes"
      :edges="edges"
      :edge-types="edgeTypes"
      fit-view-on-init
      :min-zoom="0.05"
      :max-zoom="10"
      :nodes-draggable="layoutEditable"
      :nodes-connectable="false"
      :elements-selectable="layoutEditable || edgesSelectable"
      :edges-focusable="true"
      :nodes-focusable="true"
      :edges-updatable="false"
      :zoom-on-double-click="false"
      :zoom-on-pinch="true"
      :pan-on-scroll="true"
      :zoom-on-scroll="false"
      zoom-activation-key-code="Control"
      :pan-on-drag="true"
      pan-activation-key-code="Space"
      :prevent-scrolling="true"
      @nodes-change="onNodesChange"
      @node-drag-start="onNodeDragStart"
      @node-drag="onNodeDrag"
      @node-drag-stop="onNodeDragStop"
      @pane-click="onPaneClick"
      @pane-context-menu="onPaneContextMenu"
      @node-click="onNodeClick"
      @node-context-menu="onNodeContextMenu"
      @edge-click="onEdgeClick"
      @edge-context-menu="onEdgeContextMenu"
    >
      <template #node-note="nodeProps">
        <NoteNode
          :id="nodeProps.id"
          :data="nodeProps.data as NoteNodeData"
          :selected="nodeProps.selected"
          :draggable="Boolean((nodeProps as { draggable?: boolean }).draggable)"
          @resize-end="onNodeResizeEnd"
          @open="emit('node-open', $event)"
          @focus="emit('node-focus', $event)"
          @blur="emit('node-focus', '')"
          @hover="emit('node-hover', $event)"
          @delete="emit('node-delete', $event)"
        />
      </template>

      <template #node-ghost="nodeProps">
        <GhostNote
          :id="nodeProps.id"
          :data="nodeProps.data as GhostNodeData"
          @restore="emit('ghost-restore', $event)"
        />
      </template>

      <template #node-dangling-anchor="nodeProps">
        <DanglingAnchor :id="nodeProps.id" />
      </template>

      <template #edge-linkEdge="edgeProps">
        <LinkEdge
          v-bind="edgeProps"
          @select="emit('edge-select', $event)"
          @delete="emit('edge-delete', $event)"
        />
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

.canvas-board--linking :deep(.vue-flow__pane) {
  cursor: crosshair;
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

.canvas-board :deep(.canvas-edge--removed .vue-flow__edge-path) {
  stroke: var(--color-text-faint, #94a3b8);
}

.canvas-board :deep(.canvas-edge--selected .vue-flow__edge-path) {
  stroke: var(--color-accent);
  stroke-width: 2.5;
}

.canvas-board :deep(.canvas-edge--dimmed .vue-flow__edge-path) {
  opacity: 0.15;
}

.canvas-board :deep(.canvas-edge--highlighted .vue-flow__edge-path) {
  stroke: var(--color-accent);
  stroke-width: 2.5;
}

.canvas-board :deep(.canvas-edge--hovered .vue-flow__edge-path) {
  stroke-width: 3;
}

.canvas-board :deep(.vue-flow__node.dragging .note-node) {
  box-shadow: var(--shadow-lg);
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

.canvas-board :deep(.note-flow-node--dimmed) {
  opacity: 0.28;
  transition: opacity var(--transition-fast, 0.15s ease);
}

.canvas-board :deep(.note-flow-node--link-source .note-node) {
  box-shadow:
    0 0 0 2px var(--color-accent),
    var(--shadow-md);
}

.canvas-board :deep(.note-flow-node--link-target .note-node) {
  cursor: crosshair;
}

.canvas-board :deep(.note-flow-node--link-target .note-node:hover) {
  box-shadow:
    0 0 0 2px color-mix(in srgb, var(--color-accent) 55%, transparent),
    var(--shadow-md);
}

.canvas-board :deep(.vue-flow__node-note.selected .note-node),
.canvas-board :deep(.vue-flow__node.selected .note-node) {
  box-shadow:
    0 0 0 2px var(--color-accent),
    var(--shadow-md);
}
</style>
