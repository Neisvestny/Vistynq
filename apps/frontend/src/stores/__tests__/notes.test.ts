import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useNotesStore } from '@/stores/notes'
import type { GraphNode } from '@/api/types'

function node(id: string, overrides: Partial<GraphNode> = {}): GraphNode {
  return {
    id,
    title: `Заметка ${id}`,
    importance: 3,
    isRelevant: true,
    x: 0,
    y: 0,
    width: 220,
    deletedAt: null,
    tags: [],
    authorId: 'u-1',
    ...overrides,
  }
}

describe('notes store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('setNodes replaces nodes and resets selection', () => {
    const store = useNotesStore()
    store.select('n-1')
    store.setNodes([node('n-1'), node('n-2')])
    expect(store.nodes.map((n) => n.id)).toEqual(['n-1', 'n-2'])
    expect(store.count).toBe(2)
    expect(store.selectedId).toBeNull()
  })

  it('getNodeById returns node or undefined', () => {
    const store = useNotesStore()
    store.setNodes([node('a')])
    expect(store.getNodeById('a')?.id).toBe('a')
    expect(store.getNodeById('missing')).toBeUndefined()
  })

  it('upsertNode inserts new and updates existing', () => {
    const store = useNotesStore()
    store.setNodes([node('a', { title: 'Old' })])
    store.upsertNode(node('b', { title: 'New' }))
    expect(store.nodes.map((n) => n.id)).toEqual(['a', 'b'])

    store.upsertNode(node('a', { title: 'Updated' }))
    expect(store.getNodeById('a')?.title).toBe('Updated')
    expect(store.nodes).toHaveLength(2)
  })

  it('removeNode removes node and clears selection when needed', () => {
    const store = useNotesStore()
    store.setNodes([node('a'), node('b')])
    store.select('a')
    store.removeNode('a')
    expect(store.nodes.map((n) => n.id)).toEqual(['b'])
    expect(store.selectedId).toBeNull()

    store.select('b')
    store.removeNode('a')
    expect(store.selectedId).toBe('b')
  })

  it('patchNode updates provided fields and clamps importance', () => {
    const store = useNotesStore()
    store.setNodes([node('a', { tags: ['t1'], importance: 3 })])
    store.patchNode('a', {
      title: 'T',
      importance: 10,
      isRelevant: false,
      visibility: 'private',
      tags: ['x'],
    })
    expect(store.getNodeById('a')).toMatchObject({
      title: 'T',
      importance: 5,
      isRelevant: false,
      visibility: 'private',
      tags: ['x'],
    })

    store.patchNode('a', { importance: 0 })
    expect(store.getNodeById('a')?.importance).toBe(1)
    store.patchNode('missing', { title: 'X' })
    expect(store.nodes).toHaveLength(1)
  })

  it('renameTagInNodes renames and dedupes, no-op when unchanged/missing', () => {
    const store = useNotesStore()
    store.setNodes([
      node('a', { tags: ['old', 'x'] }),
      node('b', { tags: ['old', 'old2'] }),
      node('c', { tags: ['z'] }),
    ])
    const beforeC = store.getNodeById('c')
    store.renameTagInNodes('old', 'old')
    expect(store.getNodeById('c')).toBe(beforeC)

    store.renameTagInNodes('old', 'old2')
    expect(store.getNodeById('a')?.tags).toEqual(['old2', 'x'])
    expect(store.getNodeById('b')?.tags).toEqual(['old2'])
    expect(store.getNodeById('c')).toBe(beforeC)
  })

  it('removeTagFromNodes removes tag and is no-op when absent', () => {
    const store = useNotesStore()
    store.setNodes([node('a', { tags: ['t1', 't2'] }), node('b', { tags: ['t2'] })])
    const beforeA = store.getNodeById('a')
    store.removeTagFromNodes('missing')
    expect(store.getNodeById('a')).toBe(beforeA)

    store.removeTagFromNodes('t2')
    expect(store.getNodeById('a')?.tags).toEqual(['t1'])
    expect(store.getNodeById('b')?.tags).toEqual([])
  })

  it('updateNodeGeometry updates coords/width, ignores missing and no-op', () => {
    const store = useNotesStore()
    store.setNodes([node('a'), node('b')])
    store.updateNodeGeometry('a', { x: 10, y: 20 })
    store.updateNodeGeometry('b', { width: 300 })
    store.updateNodeGeometry('missing', { x: 1 })
    expect(store.getNodeById('a')).toMatchObject({ x: 10, y: 20, width: 220 })
    expect(store.getNodeById('b')).toMatchObject({ x: 0, y: 0, width: 300 })

    const beforeA = store.getNodeById('a')
    const beforeB = store.getNodeById('b')
    store.updateNodeGeometry('a', { x: 10, y: 20, width: 220 })
    expect(store.getNodeById('a')).toBe(beforeA)
    expect(store.getNodeById('b')).toBe(beforeB)
  })

  it('filters by importance and relevance', () => {
    const store = useNotesStore()
    store.setNodes([
      node('a', { importance: 1, isRelevant: true }),
      node('b', { importance: 5, isRelevant: false }),
      node('c', { importance: 3, isRelevant: true }),
    ])
    expect(store.visibleNodes.map((n) => n.id)).toEqual(['a', 'c'])

    store.setShowIrrelevant(true)
    expect(store.visibleNodes.map((n) => n.id)).toEqual(['a', 'b', 'c'])

    store.setImportanceEnabled(1, false)
    expect(store.visibleNodes.map((n) => n.id)).toEqual(['b', 'c'])
    expect(store.visibleNodeIds).toEqual(new Set(['b', 'c']))

    store.setAllImportances(false)
    expect(store.visibleNodes).toEqual([])
    store.setAllImportances(true)
    expect(store.visibleNodes.map((n) => n.id)).toEqual(['a', 'b', 'c'])

    store.resetFilters()
    expect(store.showIrrelevant).toBe(false)
    expect(store.visibleNodes.map((n) => n.id)).toEqual(['a', 'c'])
  })

  it('importance filter clamps invalid levels and ignores same value', () => {
    const store = useNotesStore()
    store.setNodes([node('a', { importance: 1 })])
    store.setImportanceEnabled(99, false)
    expect(store.importanceFilter[5]).toBe(false)
    store.setImportanceEnabled(5, false)
    expect(store.importanceFilter[5]).toBe(false)
  })

  it('select and clear reset state', () => {
    const store = useNotesStore()
    store.setNodes([node('a')])
    store.select('a')
    expect(store.selectedId).toBe('a')
    store.select(null)
    expect(store.selectedId).toBeNull()

    store.select('a')
    store.setShowIrrelevant(true)
    store.setImportanceEnabled(1, false)
    store.clear()
    expect(store.nodes).toEqual([])
    expect(store.count).toBe(0)
    expect(store.selectedId).toBeNull()
    expect(store.showIrrelevant).toBe(false)
    expect(store.importanceFilter[1]).toBe(true)
  })
})
