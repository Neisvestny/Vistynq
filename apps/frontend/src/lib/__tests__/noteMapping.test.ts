import { describe, expect, it } from 'vitest'
import type { GraphNode, NoteDetail, Tag } from '@/api/types'
import { noteDetailToGraphNode, noteToGraphNode, tagIdsToNames } from '@/lib/noteMapping'

const tags: Tag[] = [
  { id: 't1', name: 'важное', color: '#f00' },
  { id: 't2', name: 'идея', color: '#0f0' },
]

const note: NoteDetail = {
  id: 'n1',
  canvasId: 'c1',
  authorId: 'u1',
  title: 'Заметка',
  importance: 4,
  isRelevant: true,
  visibility: 'private',
  tagIds: ['t2', 't1'],
  x: 10,
  y: 20,
  width: 300,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-02T00:00:00Z',
  bodyMd: 'тело',
  myRole: 'owner',
}

describe('noteMapping (R28)', () => {
  it('преобразует tagIds в имена и отбрасывает неизвестные', () => {
    expect(tagIdsToNames(['t2', 'unknown', 't1'], tags)).toEqual(['идея', 'важное'])
  })

  it('маппит Note в GraphNode с дефолтами', () => {
    const node = noteToGraphNode(note, tags)
    expect(node).toMatchObject({
      id: 'n1',
      title: 'Заметка',
      importance: 4,
      isRelevant: true,
      visibility: 'private',
      tags: ['идея', 'важное'],
      x: 10,
      y: 20,
      width: 300,
      authorId: 'u1',
    })
  })

  it('после сохранения сохраняет свежую локальную геометрию ноды', () => {
    const existing: GraphNode = {
      ...noteToGraphNode(note, tags),
      x: 999,
      y: 888,
      width: 500,
    }
    const next = noteDetailToGraphNode({ ...note, x: 0, y: 0, width: 0 }, existing, tags)
    expect(next.x).toBe(0)
    expect(next.y).toBe(0)
    expect(next.width).toBe(500)
  })
})
