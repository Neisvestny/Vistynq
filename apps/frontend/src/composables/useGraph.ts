import { computed } from 'vue'
import { MarkerType } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

export interface UseGraphOptions {
  interactive?: boolean
}

/**
 * Производные данные Vue Flow из stores канваса/заметок (read-only по умолчанию).
 * Единая точка маппинга для CanvasBoard; расширяема под R26/R27.
 */
export function useGraph(options: UseGraphOptions = {}) {
  const canvasStore = useCanvasStore()
  const notesStore = useNotesStore()

  const tagColors = computed<Record<string, string>>(() =>
    Object.fromEntries(canvasStore.tags.map((tag) => [tag.name, tag.color]))
  )

  const visibleNodes = computed(() => notesStore.visibleNodes)
  const visibleNodeIds = computed(() => notesStore.visibleNodeIds)

  /** Рёбра к удалённым заметкам (state=removed, to=null) — для метрики в шапке. */
  const brokenEdgesCount = computed(
    () => canvasStore.edges.filter((edge) => edge.state === 'removed').length
  )

  const flowNodes = computed<Node<NoteNodeData>[]>(() =>
    visibleNodes.value.map((node) => ({
      id: node.id,
      type: 'note',
      position: { x: node.x, y: node.y },
      width: node.width || 220,
      draggable: options.interactive === true,
      selectable: options.interactive === true,
      data: {
        title: node.title,
        importance: node.importance,
        tags: node.tags,
        tagColors: tagColors.value,
      },
    }))
  )

  const flowEdges = computed<Edge[]>(() =>
    canvasStore.edges
      .filter(
        (edge) =>
          edge.to !== null &&
          edge.state === 'ok' &&
          visibleNodeIds.value.has(edge.from) &&
          visibleNodeIds.value.has(edge.to)
      )
      .map((edge) => ({
        id: edge.id,
        source: edge.from,
        target: edge.to as string,
        type: 'default',
        selectable: options.interactive === true,
        focusable: options.interactive === true,
        deletable: false,
        markerEnd: MarkerType.ArrowClosed,
        class: edge.type === 'indirect' ? 'canvas-edge canvas-edge--indirect' : 'canvas-edge',
        ...(edge.type === 'indirect'
          ? { style: { strokeDasharray: '6 4', strokeWidth: 1.5, opacity: 0.7 } }
          : {}),
      }))
  )

  return {
    canvasStore,
    notesStore,
    tagColors,
    visibleNodes,
    visibleNodeIds,
    brokenEdgesCount,
    flowNodes,
    flowEdges,
  }
}
