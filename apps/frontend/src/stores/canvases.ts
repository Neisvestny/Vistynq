/**
 * Spisok kanvasov polzovatelya (docs/architecture.md §stores, roadmap R04, R19).
 * Dannye GET /canvases (postranichno) + sozdanie kanvasa; forma-sostoyanie dialoga
 * (newTitle, createErrors) ostayotsya lokalnym v DashboardPage.
 */
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { canvasApi } from '@/api/canvas'
import type { Canvas } from '@/api/types'

export const useCanvasesStore = defineStore('canvases', () => {
  const items = ref<Canvas[]>([])
  const loading = ref(true)
  const loadFailed = ref(false)
  const creating = ref(false)
  const deletingId = ref<string | null>(null)
  const page = ref(1)
  const perPage = ref(50)
  const total = ref(0)

  const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

  let loadSequence = 0

  async function load(nextPage = 1): Promise<void> {
    const sequence = ++loadSequence
    loading.value = true
    loadFailed.value = false
    try {
      const data = await canvasApi.list({ page: nextPage, perPage: perPage.value })
      if (sequence !== loadSequence) return
      items.value = data.items
      page.value = data.page
      perPage.value = data.perPage
      total.value = data.total
    } catch {
      if (sequence === loadSequence) loadFailed.value = true
    } finally {
      if (sequence === loadSequence) loading.value = false
    }
  }

  async function remove(id: string): Promise<void> {
    if (deletingId.value) return
    deletingId.value = id
    try {
      await canvasApi.remove(id)
      items.value = items.value.filter((canvas) => canvas.id !== id)
      total.value = Math.max(0, total.value - 1)
    } finally {
      deletingId.value = null
    }
  }

  /**
   * Sozdayot kanvas. Error NE glotaetsya — ApiError probrasyvaetsya naverkh,
   * chtoby view raznyos eyo po polyam formy (applyApiError). V items ne pushim:
   * view posle uspekha ukhodit na /canvas/:id, spisok perezagruzitsya na mount.
   */
  async function create(title: string): Promise<Canvas> {
    creating.value = true
    try {
      return await canvasApi.create({ title })
    } finally {
      creating.value = false
    }
  }

  function reset(): void {
    items.value = []
    loading.value = true
    loadFailed.value = false
    creating.value = false
    page.value = 1
    perPage.value = 50
    total.value = 0
  }

  return {
    items,
    loading,
    loadFailed,
    creating,
    page,
    perPage,
    total,
    totalPages,
    load,
    create,
    remove,
    deletingId,
    reset,
  }
})
