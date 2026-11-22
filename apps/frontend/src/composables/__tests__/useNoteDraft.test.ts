import { describe, expect, it } from 'vitest'
import type { NoteDetail } from '@/api/types'
import {
  buildSavePlan,
  draftFromDetail,
  isDraftDirty,
  validateManifest,
  type NoteDraft,
} from '@/composables/useNoteDraft'

const detail: NoteDetail = {
  id: 'n1',
  canvasId: 'c1',
  authorId: 'u1',
  title: 'Заголовок',
  importance: 3,
  isRelevant: true,
  visibility: 'public',
  tagIds: ['t1'],
  x: 0,
  y: 0,
  width: 220,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  bodyMd: 'тело',
  myRole: 'editor',
}

function clone(draft: NoteDraft): NoteDraft {
  return JSON.parse(JSON.stringify(draft)) as NoteDraft
}

describe('useNoteDraft (R28)', () => {
  it('считает неизменённый черновик чистым', () => {
    const draft = draftFromDetail(detail)
    expect(isDraftDirty(draft, clone(draft))).toBe(false)
    expect(buildSavePlan(draft, clone(draft))).toEqual({})
  })

  it('формирует минимальный PATCH только по изменённым полям', () => {
    const before = draftFromDetail(detail)
    const after = clone(before)
    after.manifest.importance = 5
    after.manifest.tagIds = ['t1', 't2']

    expect(buildSavePlan(before, after)).toEqual({
      manifestPatch: { importance: 5, tagIds: ['t1', 't2'] },
    })
  })

  it('отдельно планирует visibility и body', () => {
    const before = draftFromDetail(detail)
    const after = clone(before)
    after.manifest.visibility = 'private'
    after.bodyMd = 'новое тело\n'
    after.bodyMd = 'новое тело'

    expect(buildSavePlan(before, after)).toEqual({
      bodyMd: 'новое тело\n',
      visibility: 'private',
    })
  })

  it('обрезает заголовок при отправке', () => {
    const before = draftFromDetail(detail)
    const after = clone(before)
    after.manifest.title = '  Новый заголовок  '

    expect(buildSavePlan(before, after).manifestPatch).toEqual({ title: 'Новый заголовок' })
  })

  it('валидирует заголовок и важность', () => {
    const draft = draftFromDetail(detail)
    expect(validateManifest(draft.manifest)).toEqual([])
    expect(validateManifest({ ...draft.manifest, title: '   ', importance: 9 })).toEqual([
      'Укажите заголовок',
      'Важность должна быть от 1 до 5',
    ])
  })
})
