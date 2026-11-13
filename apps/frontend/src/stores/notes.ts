/**
 * Заметки (ноды графа) активного канваса (docs/architecture.md §stores, R04).
 * Заполняется из store `canvas` при загрузке GraphResponse; фильтр
 * importance/архива добавит R27 — сейчас скрыты только isRelevant=false.
 */
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { GraphNode } from '@/api/types'

export const useNotesStore = defineStore('notes', () => {
  const nodes = ref<GraphNode[]>([])
  const selectedId = ref<string | null>(null)

  /** Без isRelevant=false — скрыто до включения фильтра (R27). */
  const visibleNodes = computed(() => nodes.value.filter((node) => node.isRelevant))
  /** id видимых нод — для фильтрации рёбер в view. */
  const visibleNodeIds = computed(() => new Set(visibleNodes.value.map((node) => node.id)))
  const count = computed(() => nodes.value.length)

  function setNodes(next: GraphNode[]): void {
    nodes.value = next
    selectedId.value = null
  }

  function select(id: string | null): void {
    selectedId.value = id
  }

  function clear(): void {
    nodes.value = []
    selectedId.value = null
  }

  return { nodes, selectedId, visibleNodes, visibleNodeIds, count, setNodes, select, clear }
})
