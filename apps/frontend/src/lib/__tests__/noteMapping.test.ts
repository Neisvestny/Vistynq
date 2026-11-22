import { describe, expect, it } from 'vitest'
import type { GraphNode, NoteDetail, Tag } from '@/api/types'
import { noteDetailToGraphNode, noteToGraphNode, tagIdsToNames } from '@/lib/noteMapping'

const tags: Tag[] = [
  { id: 't1', name: 'vazhnoe', color: '#f00' },
  { id: 't2', name: 'ideya', color: '#0f0' },
]

const note: NoteDetail = {
  id: 'n1',
  canvasId: 'c1',
  authorId: 'u1',
  title: 'Zametka',
  importance: 4,
  isRelevant: true,
  visibility: 'private',
  tagIds: ['t2', 't1'],
  x: 10,
  y: 20,
  width: 300,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-02T00:00:00Z',
  bodyMd: 'telo',
  myRole: 'owner',
}

describe('noteMapping (R28)', () => {
  it('preobrazuet tagIds v imena i otbrasyvaet neizvestnye', () => {
    expect(tagIdsToNames(['t2', 'unknown', 't1'], tags)).toEqual(['ideya', 'vazhnoe'])
  })

  it('mappit Note v GraphNode s defoltami', () => {
    const node = noteToGraphNode(note, tags)
    expect(node).toMatchObject({
      id: 'n1',
      title: 'Zametka',
      importance: 4,
      isRelevant: true,
      visibility: 'private',
      tags: ['ideya', 'vazhnoe'],
      x: 10,
      y: 20,
      width: 300,
      authorId: 'u1',
    })
  })

  it('posle sokhraneniya sokhranyaet svezhuyu lokalnuyu geometriyu nody', () => {
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
