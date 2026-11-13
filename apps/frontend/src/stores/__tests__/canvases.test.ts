import { describe, expect, it, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/api/canvas', () => ({
  canvasApi: {
    list: vi.fn(),
    create: vi.fn(),
    graph: vi.fn(),
  },
}))

import { useCanvasesStore } from '@/stores/canvases'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import type { Canvas } from '@/api/types'

const canvas: Canvas = {
  id: 'c-1',
  title: 'Исследования',
  ownerId: 'u-1',
  noteCount: 3,
  createdAt: '2026-11-01T10:00:00.000Z',
  access: { level: 'private', myRole: 'owner', publicSlug: null },
}

describe('canvases store (R04)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(canvasApi.list).mockReset()
    vi.mocked(canvasApi.create).mockReset()
  })

  it('load заполняет items и снимает флаги loading/loadFailed', async () => {
    vi.mocked(canvasApi.list).mockResolvedValue({ items: [canvas] })
    const store = useCanvasesStore()

    expect(store.loading).toBe(true)
    await store.load()

    expect(store.items).toEqual([canvas])
    expect(store.loading).toBe(false)
    expect(store.loadFailed).toBe(false)
  })

  it('load при ошибке API ставит loadFailed и оставляет items пустыми', async () => {
    vi.mocked(canvasApi.list).mockRejectedValue(ApiError.network())
    const store = useCanvasesStore()

    await store.load()

    expect(store.loadFailed).toBe(true)
    expect(store.loading).toBe(false)
    expect(store.items).toEqual([])
  })

  it('create поднимает creating, возвращает канвас и опускает флаг', async () => {
    let flagDuringCall = false
    vi.mocked(canvasApi.create).mockImplementation(async () => {
      flagDuringCall = store.creating
      return canvas
    })
    const store = useCanvasesStore()

    const result = await store.create('Исследования')

    expect(flagDuringCall).toBe(true)
    expect(result).toEqual(canvas)
    expect(store.creating).toBe(false)
    expect(canvasApi.create).toHaveBeenCalledWith({ title: 'Исследования' })
    expect(store.items).toEqual([])
  })

  it('create пробрасывает ApiError и сбрасывает creating', async () => {
    const error = new ApiError(403, 'EMAIL_NOT_VERIFIED', 'Подтвердите email')
    vi.mocked(canvasApi.create).mockRejectedValue(error)
    const store = useCanvasesStore()

    await expect(store.create('X')).rejects.toBe(error)
    expect(store.creating).toBe(false)
  })

  it('reset очищает состояние', async () => {
    vi.mocked(canvasApi.list).mockResolvedValue({ items: [canvas] })
    const store = useCanvasesStore()
    await store.load()

    store.reset()

    expect(store.items).toEqual([])
    expect(store.loading).toBe(true)
    expect(store.loadFailed).toBe(false)
  })
})
