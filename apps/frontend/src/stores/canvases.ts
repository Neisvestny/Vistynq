/**
 * Список канвасов пользователя (docs/architecture.md §stores, roadmap R04).
 * Данные GET /canvases + создание канваса; форма-состояние диалога (newTitle,
 * createErrors) остаётся локальным в DashboardPage.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { canvasApi } from '@/api/canvas'
import type { Canvas } from '@/api/types'

export const useCanvasesStore = defineStore('canvases', () => {
  const items = ref<Canvas[]>([])
  const loading = ref(true)
  const loadFailed = ref(false)
  const creating = ref(false)

  async function load(): Promise<void> {
    loading.value = true
    loadFailed.value = false
    try {
      items.value = (await canvasApi.list()).items
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
  }

  return { items, loading, loadFailed, creating, load, create, reset }
})
