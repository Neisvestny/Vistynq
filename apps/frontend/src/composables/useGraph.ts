import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import { MarkerType } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

export interface GhostNodeData {
  kind: 'ghost'
  targetNoteId: string
  edgeId: string
  label: string
  canRestore: boolean
  /** Исходные заметки, которые ссылались на удалённую цель. */
  sourceNoteIds: string[]
}

export interface LinkEdgeData {
  edgeId: string
  edgeType: 'direct' | 'indirect'
  state: 'ok' | 'removed'
  from: string
  to: string | null
  label: string | null
  dangling: boolean
  canRestore: boolean
}

export type CanvasFlowNodeData = NoteNodeData | GhostNodeData

export interface UseGraphOptions {
  /** Включить per-node drag/resize по правам (R26). */
  editableLayout?: MaybeRefOrGetter<boolean>
  /** Выделение нод/рёбер в интерактивном режиме. */
  selectable?: MaybeRefOrGetter<boolean>
  /** Подсветка нод по тегу: остальные диммятся (R27). */
  highlightedTag?: MaybeRefOrGetter<string | null>
  /** Выбранное ребро (R30): подсветка. */
  selectedEdgeId?: MaybeRefOrGetter<string | null>
  /** Режим связывания (R30): id исходной ноды. */
  linkingSourceId?: MaybeRefOrGetter<string | null>
  /** @deprecated используйте editableLayout */
  interactive?: boolean
}

export function isGhostNodeData(data: CanvasFlowNodeData): data is GhostNodeData {
  return (data as GhostNodeData).kind === 'ghost'
}

function noteClass(
  dimmed: boolean,
  linkingSource: boolean,
  linkingTarget: boolean
): string | undefined {
  const classes = [
    dimmed ? 'note-flow-node--dimmed' : '',
    linkingSource ? 'note-flow-node--link-source' : '',
    linkingTarget ? 'note-flow-node--link-target' : '',
  ].filter(Boolean)
  return classes.length ? classes.join(' ') : undefined
}

/**
 * Производные данные Vue Flow из stores канваса/заметок.
 * Единая точка маппинга для CanvasBoard (R25) + layout/фильтры/подсветка (R26–R27)
 * + removed edges/ghost nodes/linking (R30).
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
  const selectedEdgeId = computed(() => toValue(options.selectedEdgeId) ?? null)
  const linkingSourceId = computed(() => toValue(options.linkingSourceId) ?? null)

  const visibleNodes = computed(() => notesStore.visibleNodes)
  const visibleNodeIds = computed(() => notesStore.visibleNodeIds)

  /** Рёбра к удалённым заметкам (state=removed) — для метрики в шапке. */
  const brokenEdgesCount = computed(
    () => canvasStore.edges.filter((edge) => edge.state === 'removed').length
  )

  const flowNodes = computed<Node<CanvasFlowNodeData>[]>(() => {
    const tag = highlightedTag.value
    const linkingSource = linkingSourceId.value
    const nodes: Node<CanvasFlowNodeData>[] = visibleNodes.value.map((node) => {
      const canMove = editableLayout.value && guard.canMoveResizeNode(node)
      const dimmed = tag !== null && !node.tags.includes(tag)
      const isLinkSource = linkingSource === node.id
      const isLinkTarget = linkingSource !== null && linkingSource !== node.id

      return {
        id: node.id,
        type: 'note',
        position: { x: node.x, y: node.y },
        width: node.width || 220,
        draggable: canMove,
        selectable: selectable.value,
        class: noteClass(dimmed, isLinkSource, isLinkTarget),
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

    // Ghost/synthetic nodes для removed edges (R30). Не попадают в notes store.
    const ghostByTarget = new Map<string, GhostNodeData>()
    const danglingByEdgeId = new Set<string>()

    for (const edge of canvasStore.edges) {
      if (edge.state !== 'removed') continue
      if (!visibleNodeIds.value.has(edge.from)) continue

      // Purged indirect: to === null, висячая линия.
      if (edge.to === null) {
        danglingByEdgeId.add(edge.id)
        continue
      }

      // Soft-deleted target: to задан, но ноды нет в visible set.
      if (visibleNodeIds.value.has(edge.to)) continue

      const existing = ghostByTarget.get(edge.to)
      if (existing) {
        if (!existing.sourceNoteIds.includes(edge.from)) existing.sourceNoteIds.push(edge.from)
        continue
      }
      ghostByTarget.set(edge.to, {
        kind: 'ghost',
        targetNoteId: edge.to,
        edgeId: edge.id,
        label: edge.label ?? 'ссылка удалена',
        canRestore: guard.canEditCanvas.value,
        sourceNoteIds: [edge.from],
      })
    }

    for (const ghost of ghostByTarget.values()) {
      const source = notesStore.getNodeById(ghost.sourceNoteIds[0] ?? '')
      const baseX = (source?.x ?? 0) + (source?.width ?? 220) + 80
      const baseY = source?.y ?? 0
      // Небольшое вертикальное смещение для нескольких ghost-нод от разных источников.
      const index = nodes.filter(
        (n) => isGhostNodeData(n.data) && Math.abs(n.position.x - baseX) < 20
      ).length
      nodes.push({
        id: `ghost:${ghost.targetNoteId}`,
        type: 'ghost',
        position: { x: baseX, y: baseY + index * 96 },
        width: 220,
        draggable: false,
        selectable: false,
        focusable: false,
        class: 'ghost-flow-node',
        data: ghost,
      })
    }

    for (const edgeId of danglingByEdgeId) {
      const edge = canvasStore.edges.find((e) => e.id === edgeId)
      if (!edge) continue
      const source = notesStore.getNodeById(edge.from)
      const baseX = (source?.x ?? 0) + (source?.width ?? 220) + 90
      const baseY = (source?.y ?? 0) + 24
      nodes.push({
        id: `dangling:${edge.id}`,
        type: 'dangling-anchor',
        position: { x: baseX, y: baseY },
        width: 1,
        height: 1,
        draggable: false,
        selectable: false,
        focusable: false,
        class: 'dangling-flow-node',
        data: {
          kind: 'ghost',
          targetNoteId: '',
          edgeId: edge.id,
          label: edge.label ?? 'ссылка удалена',
          canRestore: false,
          sourceNoteIds: [edge.from],
        } satisfies GhostNodeData,
      })
    }

    return nodes
  })

  const flowEdges = computed<Edge<LinkEdgeData>[]>(() => {
    const tag = highlightedTag.value
    const selected = selectedEdgeId.value
    const canEdit = guard.canEditCanvas.value
    const visibleIds = new Set(flowNodes.value.map((n) => n.id))

    return canvasStore.edges.flatMap((edge) => {
      if (!visibleNodeIds.value.has(edge.from)) return []

      const fromTags = notesStore.getNodeById(edge.from)?.tags ?? []
      const toTags =
        edge.to && visibleNodeIds.value.has(edge.to)
          ? (notesStore.getNodeById(edge.to)?.tags ?? [])
          : []
      const dimmedByTag =
        tag !== null && !(fromTags.includes(tag) || (edge.to ? toTags.includes(tag) : false))

      const isIndirect = edge.type === 'indirect'
      const isRemoved = edge.state === 'removed'
      const isSelected = selected === edge.id

      let target: string | null = null
      let dangling = false

      if (edge.state === 'ok') {
        if (!edge.to || !visibleNodeIds.value.has(edge.to)) return []
        target = edge.to
      } else if (edge.to === null) {
        target = `dangling:${edge.id}`
        dangling = true
      } else if (visibleNodeIds.value.has(edge.to)) {
        // Removed edge to a live visible target - unusual, but render directly.
        target = edge.to
      } else {
        target = `ghost:${edge.to}`
      }

      if (!target || !visibleIds.has(target) || !visibleIds.has(edge.from)) return []

      const classes = [
        'canvas-edge',
        isIndirect ? 'canvas-edge--indirect' : 'canvas-edge--direct',
        isRemoved ? 'canvas-edge--removed' : '',
        dangling ? 'canvas-edge--dangling' : '',
        dimmedByTag ? 'canvas-edge--dimmed' : '',
        isSelected ? 'canvas-edge--selected' : '',
      ]
        .filter(Boolean)
        .join(' ')

      const style = isRemoved
        ? {
            strokeDasharray: dangling ? '5 5' : '6 4',
            strokeWidth: 1.5,
            opacity: dimmedByTag ? 0.15 : 0.8,
          }
        : isIndirect
          ? {
              strokeDasharray: '6 4',
              strokeWidth: 1.5,
              opacity: dimmedByTag ? 0.15 : 0.7,
            }
          : dimmedByTag
            ? { opacity: 0.15 }
            : undefined

      return [
        {
          id: edge.id,
          source: edge.from,
          target,
          type: 'linkEdge',
          selectable: canEdit && edge.type === 'direct',
          focusable: canEdit && edge.type === 'direct',
          deletable: false,
          selected: isSelected,
          markerEnd: dangling ? undefined : MarkerType.ArrowClosed,
          class: classes,
          ...(style ? { style } : {}),
          data: {
            edgeId: edge.id,
            edgeType: edge.type,
            state: edge.state,
            from: edge.from,
            to: edge.to,
            label: edge.label,
            dangling,
            canRestore: canEdit,
          },
        } satisfies Edge<LinkEdgeData>,
      ]
    })
  })

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
