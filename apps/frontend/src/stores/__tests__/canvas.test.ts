import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/api/canvas', () => ({
  canvasApi: {
    list: vi.fn(),
    create: vi.fn(),
    graph: vi.fn(),
  },
}))

import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import type { GraphNode, GraphResponse } from '@/api/types'

function node(id: string): GraphNode {
  return {
    id,
    title: `Заметка ${id}`,
    importance: 1,
    isRelevant: true,
    x: 0,
    y: 0,
    width: 220,
    deletedAt: null,
    tags: [],
    authorId: 'u-1',
  }
}

const graph: GraphResponse = {
  canvas: {
    id: 'c-1',
    title: 'Исследования',
    ownerId: 'u-1',
    createdAt: '2026-11-01T10:00:00.000Z',
    access: { level: 'private', myRole: 'owner', publicSlug: null },
  },
  nodes: [node('n-1'), node('n-2')],
  edges: [{ id: 'e-1', type: 'direct', from: 'n-1', to: 'n-2', state: 'ok', label: null }],
  tags: [{ id: 't-1', name: 'важное', color: '#ff0000' }],
}

describe('canvas store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('load spreads GraphResponse across canvas and notes stores', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValue(graph)
    const store = useCanvasStore()
    await store.load('c-1')

    expect(store.canvas).toEqual(graph.canvas)
    expect(store.edges).toEqual(graph.edges)
    expect(store.tags).toEqual(graph.tags)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
    expect(useNotesStore().nodes).toEqual(graph.nodes)
  })

  it('load("") returns 404 without API call and clears state', async () => {
    const store = useCanvasStore()
    const notes = useNotesStore()
    notes.setNodes([node('n-9')])

    await store.load('')
    expect(canvasApi.graph).not.toHaveBeenCalled()
    expect(store.error?.status).toBe(404)
    expect(store.canvas).toBeNull()
    expect(notes.nodes).toEqual([])
  })

  it('API error clears state and stores ApiError', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValueOnce(graph)
    const store = useCanvasStore()
    await store.load('c-1')

    vi.mocked(canvasApi.graph).mockRejectedValueOnce(
      new ApiError(404, 'NOT_FOUND', 'Канвас не найден')
    )
    await store.load('missing')
    expect(store.error?.status).toBe(404)
    expect(store.canvas).toBeNull()
    expect(store.edges).toEqual([])
    expect(store.tags).toEqual([])
    expect(useNotesStore().nodes).toEqual([])
  })

  it('non-ApiError is mapped to NETWORK ApiError', async () => {
    vi.mocked(canvasApi.graph).mockRejectedValueOnce(new TypeError('fetch failed'))
    const store = useCanvasStore()
    await store.load('c-1')
    expect(store.error?.code).toBe('NETWORK')
    expect(store.error?.status).toBe(0)
  })

  it('reset clears data and restores loading/error defaults', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValue(graph)
    const store = useCanvasStore()
    await store.load('c-1')
    store.error = new ApiError(500, 'X', 'x')
    store.reset()
    expect(store.canvas).toBeNull()
    expect(store.edges).toEqual([])
    expect(store.tags).toEqual([])
    expect(store.loading).toBe(true)
    expect(store.error).toBeNull()
    expect(useNotesStore().nodes).toEqual([])
  })
})
