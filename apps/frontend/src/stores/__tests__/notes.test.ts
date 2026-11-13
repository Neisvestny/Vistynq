import { describe, expect, it, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useNotesStore } from '@/stores/notes'
import type { GraphNode } from '@/api/types'

function node(id: string, isRelevant = true): GraphNode {
  return {
    id,
    title: `Заметка ${id}`,
    importance: 1,
    isRelevant,
    x: 0,
    y: 0,
    width: 220,
    deletedAt: null,
    tags: [],
    authorId: 'u-1',
  }
}

describe('notes store (R04)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('setNodes заменяет массив и сбрасывает выбор', () => {
    const store = useNotesStore()
    store.select('n-1')

    store.setNodes([node('n-1'), node('n-2')])

    expect(store.nodes.map((n) => n.id)).toEqual(['n-1', 'n-2'])
    expect(store.count).toBe(2)
    expect(store.selectedId).toBeNull()
  })

  it('visibleNodes отфильтровывает isRelevant=false, visibleNodeIds — только видимые', () => {
    const store = useNotesStore()
    store.setNodes([node('a'), node('b', false), node('c')])

    expect(store.visibleNodes.map((n) => n.id)).toEqual(['a', 'c'])
    expect([...store.visibleNodeIds]).toEqual(['a', 'c'])
    expect(store.count).toBe(3)
  })

  it('select удерживает id и снимается через select(null)', () => {
    const store = useNotesStore()
    store.setNodes([node('a')])

    store.select('a')
    expect(store.selectedId).toBe('a')

    store.select(null)
    expect(store.selectedId).toBeNull()
  })

  it('clear опустошает ноды и выбор', () => {
    const store = useNotesStore()
    store.setNodes([node('a')])
    store.select('a')

    store.clear()

    expect(store.nodes).toEqual([])
    expect(store.count).toBe(0)
    expect(store.visibleNodeIds.size).toBe(0)
    expect(store.selectedId).toBeNull()
  })
})
