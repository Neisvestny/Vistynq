/**
 * Список канвасов пользователя (docs/architecture.md §stores, roadmap R04, R19).
 * Данные GET /canvases (постранично) + создание канваса; форма-состояние диалога
 * (newTitle, createErrors) остаётся локальным в DashboardPage.
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
  const page = ref(1)
  const perPage = ref(50)
  const total = ref(0)

  const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

  async function load(nextPage = 1): Promise<void> {
    loading.value = true
    loadFailed.value = false
    try {
      const data = await canvasApi.list({ page: nextPage, perPage: perPage.value })
      items.value = data.items
      page.value = data.page
      perPage.value = data.perPage
      total.value = data.total
    } catch {
      loadFailed.value = true
    } finally {
      loading.value = false
    }
  }

  /**
   * Создаёт канвас. Ошибка НЕ глотается — ApiError пробрасывается наверх,
   * чтобы view разнёс её по полям формы (applyApiError). В items не пушим:
   * view после успеха уходит на /canvas/:id, список перезагрузится на mount.
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
    reset,
  }
})
