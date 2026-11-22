import { describe, expect, it, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'
import type { Canvas, GraphEdge, GraphNode, Tag } from '@/api/types'
import { useGraph } from '../useGraph'

function canvas(): Canvas {
  return {
    id: 'c-1',
    title: 'Test',
    ownerId: 'u-1',
    access: { level: 'private', myRole: 'owner', publicSlug: null },
    createdAt: '2026-01-01T00:00:00.000Z',
  }
}

function node(partial: Partial<GraphNode> & { id: string }): GraphNode {
  return {
    id: partial.id,
    title: partial.title ?? 'Zametka',
    importance: partial.importance ?? 1,
    isRelevant: partial.isRelevant ?? true,
    visibility: partial.visibility,
    x: partial.x ?? 0,
    y: partial.y ?? 0,
    width: partial.width ?? 220,
    deletedAt: partial.deletedAt ?? null,
    tags: partial.tags ?? [],
    authorId: partial.authorId ?? 'u-1',
  }
}

function edge(partial: Partial<GraphEdge> & { id: string; from: string }): GraphEdge {
  return {
    id: partial.id,
    type: partial.type ?? 'direct',
    from: partial.from,
    to: partial.to === undefined ? null : partial.to,
    state: partial.state ?? 'ok',
    label: partial.label ?? null,
  }
}

describe('useGraph', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('mappit vidimye nody, ok-ryobra i removed dangling edge (R30)', () => {
    const c = useCanvasStore()
    const n = useNotesStore()
    const tags: Tag[] = [{ id: 't1', name: 'vazhnoe', color: '#f00' }]

    c.canvas = canvas()
    c.tags = tags
    c.edges = [
      edge({ id: 'e-ok', from: 'n1', to: 'n2', state: 'ok', type: 'direct' }),
      edge({ id: 'e-ind', from: 'n1', to: 'n2', state: 'ok', type: 'indirect' }),
      edge({
        id: 'e-removed',
        from: 'n1',
        to: null,
        state: 'removed',
        type: 'indirect',
        label: '[[deleted]]',
      }),
      edge({ id: 'e-hidden', from: 'n1', to: 'n3', state: 'ok' }),
    ]
    n.setNodes([
      node({ id: 'n1', title: 'A', importance: 3, tags: ['vazhnoe'] }),
      node({ id: 'n2', title: 'B' }),
      node({ id: 'n3', title: 'C', isRelevant: false }),
    ])

    const g = useGraph()

    expect(g.flowNodes.value.map((x) => x.id)).toEqual(['n1', 'n2', 'dangling:e-removed'])
    expect(g.flowNodes.value[0]?.draggable).toBe(false)
    expect(g.flowNodes.value[0]?.data).toMatchObject({ tagColors: { vazhnoe: '#f00' } })
    expect(g.flowEdges.value.map((x) => x.id).sort()).toEqual(['e-ind', 'e-ok', 'e-removed'])
    const indirect = g.flowEdges.value.find((x) => x.id === 'e-ind')
    expect(indirect?.class).toContain('canvas-edge--indirect')
    expect(indirect?.style).toMatchObject({ strokeDasharray: '6 4' })
    const removed = g.flowEdges.value.find((x) => x.id === 'e-removed')
    expect(removed?.class).toContain('canvas-edge--removed')
    expect(removed?.class).toContain('canvas-edge--dangling')
    expect(removed?.data).toMatchObject({ dangling: true, state: 'removed', edgeType: 'indirect' })
    expect(g.brokenEdgesCount.value).toBe(1)
  })

  it('sozdayot ghost node dlya removed edge na soft-deleted target (R30)', () => {
    const c = useCanvasStore()
    const n = useNotesStore()
    c.canvas = canvas()
    c.tags = []
    c.edges = [
      edge({
        id: 'e-ghost',
        from: 'n1',
        to: 'n-deleted',
        state: 'removed',
        type: 'direct',
        label: null,
      }),
    ]
    n.setNodes([node({ id: 'n1', title: 'A', x: 10, y: 20, width: 220 })])

    const g = useGraph()

    expect(g.flowNodes.value.map((x) => x.id)).toEqual(['n1', 'ghost:n-deleted'])
    const ghost = g.flowNodes.value.find((x) => x.id === 'ghost:n-deleted')
    expect(ghost?.type).toBe('ghost')
    expect(ghost?.draggable).toBe(false)
    expect(ghost?.data).toMatchObject({
      kind: 'ghost',
      targetNoteId: 'n-deleted',
      canRestore: true,
    })

    expect(g.flowEdges.value).toHaveLength(1)
    expect(g.flowEdges.value[0]).toMatchObject({
      id: 'e-ghost',
      source: 'n1',
      target: 'ghost:n-deleted',
    })
    expect(g.flowEdges.value[0]?.class).toContain('canvas-edge--removed')
  })

  it('v rezhime editableLayout delaet nody peretaskivaemymi po pravam (owner kanvasa)', () => {
    const c = useCanvasStore()
    const n = useNotesStore()
    c.canvas = canvas()
    c.edges = []
    c.tags = []
    n.setNodes([node({ id: 'n1', authorId: 'u-2' }), node({ id: 'n2', authorId: 'u-1' })])

    const g = useGraph({ editableLayout: true })

    expect(g.flowNodes.value.every((x) => x.draggable)).toBe(true)
    expect(g.flowNodes.value.every((x) => x.selectable)).toBe(true)
  })
})
