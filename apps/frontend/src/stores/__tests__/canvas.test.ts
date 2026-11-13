import { describe, expect, it, vi, beforeEach } from 'vitest'
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
  edges: [
    {
      id: 'e-1',
      type: 'direct',
      from: 'n-1',
      to: 'n-2',
      state: 'ok',
      label: null,
    },
  ],
  tags: [{ id: 't-1', name: 'важное', color: '#ff0000' }],
}

describe('canvas store (R04)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(canvasApi.graph).mockReset()
  })

  it('load раскладывает GraphResponse: canvas/edges/tags сюда, nodes — в notes-store', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValue(graph)
    const store = useCanvasStore()

    await store.load('c-1')

    expect(store.canvas).toEqual(graph.canvas)
    expect(store.edges).toEqual(graph.edges)
    expect(store.tags).toEqual(graph.tags)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()

    const notes = useNotesStore()
    expect(notes.nodes).toEqual(graph.nodes)
    expect(notes.visibleNodeIds).toEqual(new Set(['n-1', 'n-2']))
    expect(canvasApi.graph).toHaveBeenCalledWith('c-1')
  })

  it('load("") → 404 без вызова API и с очисткой notes', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValue(graph)
    const store = useCanvasStore()
    const notes = useNotesStore()
    notes.setNodes([node('n-9')])

    await store.load('')

    expect(store.error).toBeInstanceOf(ApiError)
    expect(store.error?.status).toBe(404)
    expect(canvasApi.graph).not.toHaveBeenCalled()
    expect(store.canvas).toBeNull()
    expect(notes.nodes).toEqual([])
  })

  it('ошибка API → error установлен, canvas=null, notes очищен', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValue(graph)
    const store = useCanvasStore()
    const notes = useNotesStore()
    await store.load('c-1')

    vi.mocked(canvasApi.graph).mockRejectedValue(new ApiError(404, 'NOT_FOUND', 'Канвас не найден'))
    await store.load('missing')

    expect(store.error?.status).toBe(404)
    expect(store.canvas).toBeNull()
    expect(store.edges).toEqual([])
    expect(store.tags).toEqual([])
    expect(notes.nodes).toEqual([])
    expect(store.loading).toBe(false)
  })

  it('сетевая ошибка маппится в ApiError с кодом NETWORK', async () => {
    vi.mocked(canvasApi.graph).mockRejectedValue(new TypeError('fetch failed'))
    const store = useCanvasStore()

    await store.load('c-1')

    expect(store.error?.code).toBe('NETWORK')
    expect(store.error?.status).toBe(0)
  })

  it('reset очищает всё и возвращает loading=true', async () => {
    vi.mocked(canvasApi.graph).mockResolvedValue(graph)
    const store = useCanvasStore()
    await store.load('c-1')

    store.reset()

    expect(store.canvas).toBeNull()
    expect(store.loading).toBe(true)
    expect(store.error).toBeNull()
    expect(useNotesStore().nodes).toEqual([])
  })
})
