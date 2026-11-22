/**
 * Active canvas (docs/architecture.md §stores, R04): canvas/edges/tags from
 * GraphResponse. Nodes go to `notes` store - no duplication.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import type { Canvas, GraphEdge, GraphTag } from '@/api/types'
import { tGlobal } from '@/i18n'
import { useNotesStore } from '@/stores/notes'

export const useCanvasStore = defineStore('canvas', () => {
  const canvas = ref<Canvas | null>(null)
  const edges = ref<GraphEdge[]>([])
  const tags = ref<GraphTag[]>([])
  const loading = ref(true)
  const error = ref<ApiError | null>(null)

  /** Full load: nodes go to notes-store, rest goes here. */
  async function load(id: string): Promise<void> {
    if (id === '') {
      error.value = new ApiError(404, 'NOT_FOUND', tGlobal('canvas.notFoundError'))
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
