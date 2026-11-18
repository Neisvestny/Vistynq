import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import { MarkerType } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

export interface UseGraphOptions {
  /** Включить per-node drag/resize по правам (R26). */
  editableLayout?: MaybeRefOrGetter<boolean>
  /** Выделение нод/рёбер в интерактивном режиме. */
  selectable?: MaybeRefOrGetter<boolean>
  /** Подсветка нод по тегу: остальные диммятся (R27). */
  highlightedTag?: MaybeRefOrGetter<string | null>
  /** @deprecated используйте editableLayout */
  interactive?: boolean
}

/**
 * Производные данные Vue Flow из stores канваса/заметок.
 * Единая точка маппинга для CanvasBoard (R25) + layout/фильтры/подсветка (R26–R27).
 */
export function useGraph(options: UseGraphOptions = {}) {
  const canvasStore = useCanvasStore()
  const notesStore = useNotesStore()
  const guard = useAccessGuard()

  const tagColors = computed<Record<string, string>>(() =>
    Object.fromEntries(canvasStore.tags.map((tag) => [tag.name, tag.color]))
  )

  const editableLayout = computed(() => {
    if (options.interactive === true) return true
    return toValue(options.editableLayout) === true
  })
  const selectable = computed(() => toValue(options.selectable) === true || editableLayout.value)
  const highlightedTag = computed(() => toValue(options.highlightedTag) ?? null)

  const visibleNodes = computed(() => notesStore.visibleNodes)
  const visibleNodeIds = computed(() => notesStore.visibleNodeIds)

  /** Рёбра к удалённым заметкам (state=removed, to=null) — для метрики в шапке. */
  const brokenEdgesCount = computed(
    () => canvasStore.edges.filter((edge) => edge.state === 'removed').length
  )

  const flowNodes = computed<Node<NoteNodeData>[]>(() =>
    visibleNodes.value.map((node) => {
      const canMove = editableLayout.value && guard.canMoveResizeNode(node)
      const tag = highlightedTag.value
      const dimmed = tag !== null && !node.tags.includes(tag)

      return {
        id: node.id,
        type: 'note',
        position: { x: node.x, y: node.y },
        width: node.width || 220,
        draggable: canMove,
        selectable: selectable.value,
        class: dimmed ? 'note-flow-node--dimmed' : undefined,
        data: {
          title: node.title,
          importance: node.importance,
          tags: node.tags,
          tagColors: tagColors.value,
          visibility: node.visibility,
          authorId: node.authorId,
          isRelevant: node.isRelevant,
          dimmed,
          canMove,
        },
      }
    })
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
      .map((edge) => {
        const tag = highlightedTag.value
        const dimmedByTag =
          tag !== null &&
          !(
            (notesStore.getNodeById(edge.from)?.tags.includes(tag) ?? false) ||
            (notesStore.getNodeById(edge.to as string)?.tags.includes(tag) ?? false)
          )
        const isIndirect = edge.type === 'indirect'

        return {
          id: edge.id,
          source: edge.from,
          target: edge.to as string,
          type: 'default',
          selectable: selectable.value,
          focusable: selectable.value,
          deletable: false,
          markerEnd: MarkerType.ArrowClosed,
          class: [
            'canvas-edge',
            isIndirect ? 'canvas-edge--indirect' : '',
            dimmedByTag ? 'canvas-edge--dimmed' : '',
          ]
            .filter(Boolean)
            .join(' '),
          ...(isIndirect
            ? {
                style: {
                  strokeDasharray: '6 4',
                  strokeWidth: 1.5,
                  opacity: dimmedByTag ? 0.15 : 0.7,
                },
              }
            : dimmedByTag
              ? { style: { opacity: 0.15 } }
              : {}),
        }
      })
  )

  return {
    canvasStore,
    notesStore,
    guard,
    tagColors,
    visibleNodes,
    visibleNodeIds,
    brokenEdgesCount,
    flowNodes,
    flowEdges,
  }
}
