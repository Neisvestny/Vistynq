/**
 * Заметки (ноды графа) активного канваса (docs/architecture.md §stores, R04).
 * Заполняется из store `canvas` при загрузке GraphResponse.
 * Фильтры видимости (важность/актуальность) — локальные (R27).
 */
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { GraphNode, NoteVisibility } from '@/api/types'

export interface NodeGeometryPatch {
  x?: number
  y?: number
  width?: number
}

export interface NodePatch {
  title?: string
  importance?: number
  isRelevant?: boolean
  visibility?: NoteVisibility
  tags?: string[]
}

function clampImportance(value: number): number {
  if (!Number.isFinite(value)) return 3
  return Math.min(5, Math.max(1, Math.round(value)))
}

function allImportanceLevels(): Record<number, boolean> {
  return { 1: true, 2: true, 3: true, 4: true, 5: true }
}

export const useNotesStore = defineStore('notes', () => {
  const nodes = ref<GraphNode[]>([])
  const selectedId = ref<string | null>(null)

  // R27: локальные фильтры (не влияют на БД)
  const importanceFilter = ref<Record<number, boolean>>(allImportanceLevels())
  const showIrrelevant = ref(false)

  const visibleNodes = computed(() =>
    nodes.value.filter((node) => {
      if (!showIrrelevant.value && !node.isRelevant) return false
      const level = clampImportance(node.importance)
      return importanceFilter.value[level] !== false
    })
  )
  /** id видимых нод — для фильтрации рёбер в view. */
  const visibleNodeIds = computed(() => new Set(visibleNodes.value.map((node) => node.id)))
  const count = computed(() => nodes.value.length)

  function getNodeById(id: string): GraphNode | undefined {
    return nodes.value.find((node) => node.id === id)
  }

  function setNodes(next: GraphNode[]): void {
    nodes.value = next
    selectedId.value = null
  }

  function upsertNode(next: GraphNode): void {
    const index = nodes.value.findIndex((node) => node.id === next.id)
    if (index === -1) {
      nodes.value = [...nodes.value, next]
      return
    }
    const copy = nodes.value.slice()
    copy[index] = next
    nodes.value = copy
  }

  function removeNode(id: string): void {
    nodes.value = nodes.value.filter((node) => node.id !== id)
    if (selectedId.value === id) selectedId.value = null
  }

  function patchNode(id: string, patch: NodePatch): void {
    const index = nodes.value.findIndex((node) => node.id === id)
    if (index === -1) return
    const current = nodes.value[index]
    if (!current) return
    const next: GraphNode = {
      ...current,
      ...(patch.title !== undefined ? { title: patch.title } : {}),
      ...(patch.importance !== undefined ? { importance: clampImportance(patch.importance) } : {}),
      ...(patch.isRelevant !== undefined ? { isRelevant: patch.isRelevant } : {}),
      ...(patch.visibility !== undefined ? { visibility: patch.visibility } : {}),
      ...(patch.tags !== undefined ? { tags: patch.tags } : {}),
    }
    const copy = nodes.value.slice()
    copy[index] = next
    nodes.value = copy
  }

  function renameTagInNodes(fromName: string, toName: string): void {
    if (fromName === toName) return
    let changed = false
    const copy = nodes.value.map((node) => {
      if (!node.tags.includes(fromName)) return node
      changed = true
      const tags = node.tags.map((t) => (t === fromName ? toName : t))
      return { ...node, tags: Array.from(new Set(tags)) }
    })
    if (changed) nodes.value = copy
  }

  function removeTagFromNodes(tagName: string): void {
    let changed = false
    const copy = nodes.value.map((node) => {
      if (!node.tags.includes(tagName)) return node
      changed = true
      return { ...node, tags: node.tags.filter((t) => t !== tagName) }
    })
    if (changed) nodes.value = copy
  }

  /** Локальное (оптимистичное) обновление позиции/ширины ноды (R26). */
  function updateNodeGeometry(id: string, patch: NodeGeometryPatch): void {
    const index = nodes.value.findIndex((node) => node.id === id)
    if (index === -1) return

    const current = nodes.value[index]
    if (!current) return

    const next: GraphNode = {
      ...current,
      ...(patch.x !== undefined ? { x: patch.x } : {}),
      ...(patch.y !== undefined ? { y: patch.y } : {}),
      ...(patch.width !== undefined ? { width: patch.width } : {}),
    }

    // Избегаем лишней реактивной записи, если значения не изменились.
    if (next.x === current.x && next.y === current.y && next.width === current.width) return

    const copy = nodes.value.slice()
    copy[index] = next
    nodes.value = copy
  }

  function setImportanceEnabled(level: number, enabled: boolean): void {
    const key = clampImportance(level)
    if (importanceFilter.value[key] === enabled) return
    importanceFilter.value = { ...importanceFilter.value, [key]: enabled }
  }

  function setAllImportances(enabled: boolean): void {
    importanceFilter.value = { 1: enabled, 2: enabled, 3: enabled, 4: enabled, 5: enabled }
  }

  function setShowIrrelevant(value: boolean): void {
    showIrrelevant.value = value
  }

  function resetFilters(): void {
    importanceFilter.value = allImportanceLevels()
    showIrrelevant.value = false
  }

  function select(id: string | null): void {
    selectedId.value = id
  }

  function clear(): void {
    nodes.value = []
    selectedId.value = null
    resetFilters()
  }

  return {
    nodes,
    selectedId,
    importanceFilter,
    showIrrelevant,
    visibleNodes,
    visibleNodeIds,
    count,
    getNodeById,
    setNodes,
    upsertNode,
    removeNode,
    patchNode,
    renameTagInNodes,
    removeTagFromNodes,
    updateNodeGeometry,
    setImportanceEnabled,
    setAllImportances,
    setShowIrrelevant,
    resetFilters,
    select,
    clear,
  }
})
