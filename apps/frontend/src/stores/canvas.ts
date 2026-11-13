/**
 * Активный канвас (docs/architecture.md §stores, R04): canvas/edges/tags из
 * GraphResponse. Узлы (nodes) уходят в store `notes` — без дублирования.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import type { Canvas, GraphEdge, GraphTag } from '@/api/types'
import { useNotesStore } from '@/stores/notes'

export const useCanvasStore = defineStore('canvas', () => {
  const canvas = ref<Canvas | null>(null)
  const edges = ref<GraphEdge[]>([])
  const tags = ref<GraphTag[]>([])
  const loading = ref(true)
  const error = ref<ApiError | null>(null)

  /** Полная загрузка: узлы раскладываются в notes-store, остальное — сюда. */
  async function load(id: string): Promise<void> {
    if (id === '') {
      error.value = new ApiError(404, 'NOT_FOUND', 'Канвас не найден')
      loading.value = false
      clear()
      return
    }

    loading.value = true
    error.value = null
    try {
      const graph = await canvasApi.graph(id)
      canvas.value = graph.canvas
      edges.value = graph.edges
      tags.value = graph.tags
      useNotesStore().setNodes(graph.nodes)
    } catch (e) {
      error.value = e instanceof ApiError ? e : ApiError.network()
      clear()
    } finally {
      loading.value = false
    }
  }

  function clear(): void {
    canvas.value = null
    edges.value = []
    tags.value = []
    useNotesStore().clear()
  }

  function reset(): void {
    clear()
    loading.value = true
    error.value = null
  }

  return { canvas, edges, tags, loading, error, load, reset }
})
