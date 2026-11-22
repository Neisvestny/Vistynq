import { beforeEach, describe, expect, it, vi } from 'vitest'
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
  title: 'Issledovaniya',
  ownerId: 'u-1',
  noteCount: 3,
  createdAt: '2026-11-01T10:00:00.000Z',
  access: { level: 'private', myRole: 'owner', publicSlug: null },
}

describe('canvases store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('load fills items and pagination metadata', async () => {
    vi.mocked(canvasApi.list).mockResolvedValue({ items: [canvas], page: 1, perPage: 50, total: 1 })
    const store = useCanvasesStore()
    await store.load()

    expect(store.items).toEqual([canvas])
    expect(store.page).toBe(1)
    expect(store.perPage).toBe(50)
    expect(store.total).toBe(1)
    expect(store.totalPages).toBe(1)
    expect(store.loading).toBe(false)
    expect(store.loadFailed).toBe(false)
    expect(canvasApi.list).toHaveBeenCalledWith({ page: 1, perPage: 50 })
  })

  it('load uses requested page and computes totalPages', async () => {
    vi.mocked(canvasApi.list).mockResolvedValue({
      items: [canvas],
      page: 2,
      perPage: 50,
      total: 60,
    })
    const store = useCanvasesStore()
    await store.load(2)
    expect(store.page).toBe(2)
    expect(store.totalPages).toBe(2)
    expect(canvasApi.list).toHaveBeenCalledWith({ page: 2, perPage: 50 })
  })

  it('totalPages is at least 1 when empty', () => {
    const store = useCanvasesStore()
    expect(store.total).toBe(0)
    expect(store.totalPages).toBe(1)
  })

  it('load sets loadFailed on error and clears it on next success', async () => {
    vi.mocked(canvasApi.list).mockRejectedValueOnce(ApiError.network())
    const store = useCanvasesStore()
    await store.load()
    expect(store.loadFailed).toBe(true)
    expect(store.items).toEqual([])

    vi.mocked(canvasApi.list).mockResolvedValueOnce({
      items: [canvas],
      page: 1,
      perPage: 50,
      total: 1,
    })
    await store.load()
    expect(store.loadFailed).toBe(false)
    expect(store.items).toEqual([canvas])
  })

  it('create returns canvas, toggles creating, and propagates errors', async () => {
    let creatingDuring = false
    vi.mocked(canvasApi.create).mockImplementation(async () => {
      creatingDuring = store.creating
      return canvas
    })
    const store = useCanvasesStore()
    const result = await store.create('Issledovaniya')
    expect(creatingDuring).toBe(true)
    expect(result).toEqual(canvas)
    expect(store.creating).toBe(false)

    const error = new ApiError(403, 'EMAIL_NOT_VERIFIED', 'Podtverdite email')
    vi.mocked(canvasApi.create).mockRejectedValueOnce(error)
    await expect(store.create('X')).rejects.toBe(error)
    expect(store.creating).toBe(false)
  })

  it('reset restores initial state', async () => {
    vi.mocked(canvasApi.list).mockResolvedValue({
      items: [canvas],
      page: 2,
      perPage: 50,
      total: 60,
    })
    const store = useCanvasesStore()
    await store.load(2)
    store.reset()
    expect(store.items).toEqual([])
    expect(store.loading).toBe(true)
    expect(store.loadFailed).toBe(false)
    expect(store.creating).toBe(false)
    expect(store.page).toBe(1)
    expect(store.perPage).toBe(50)
    expect(store.total).toBe(0)
  })
})
