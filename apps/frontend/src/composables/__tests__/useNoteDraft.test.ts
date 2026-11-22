import { beforeEach, describe, expect, it } from 'vitest'
import { setI18nLocale } from '@/i18n'
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
  title: 'Zagolovok',
  importance: 3,
  isRelevant: true,
  visibility: 'public',
  tagIds: ['t1'],
  x: 0,
  y: 0,
  width: 220,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  bodyMd: 'telo',
  myRole: 'editor',
}

function clone(draft: NoteDraft): NoteDraft {
  return JSON.parse(JSON.stringify(draft)) as NoteDraft
}

describe('useNoteDraft (R28)', () => {
  beforeEach(() => setI18nLocale('en'))
  it('schitaet neizmenyonnyy chernovik chistym', () => {
    const draft = draftFromDetail(detail)
    expect(isDraftDirty(draft, clone(draft))).toBe(false)
    expect(buildSavePlan(draft, clone(draft))).toEqual({})
  })

  it('formiruet minimalnyy PATCH tolko po izmenyonnym polyam', () => {
    const before = draftFromDetail(detail)
    const after = clone(before)
    after.manifest.importance = 5
    after.manifest.tagIds = ['t1', 't2']

    expect(buildSavePlan(before, after)).toEqual({
      manifestPatch: { importance: 5, tagIds: ['t1', 't2'] },
    })
  })

  it('otdelno planiruet visibility i body', () => {
    const before = draftFromDetail(detail)
    const after = clone(before)
    after.manifest.visibility = 'private'
    after.bodyMd = 'novoe telo\n'
    after.bodyMd = 'novoe telo'

    expect(buildSavePlan(before, after)).toEqual({
      bodyMd: 'novoe telo\n',
      visibility: 'private',
    })
  })

  it('obrezaet zagolovok pri otpravke', () => {
    const before = draftFromDetail(detail)
    const after = clone(before)
    after.manifest.title = '  Novyy zagolovok  '

    expect(buildSavePlan(before, after).manifestPatch).toEqual({ title: 'Novyy zagolovok' })
  })

  it('validiruet zagolovok i vazhnost', () => {
    const draft = draftFromDetail(detail)
    expect(validateManifest(draft.manifest)).toEqual([])
    expect(validateManifest({ ...draft.manifest, title: '   ', importance: 9 })).toEqual([
      'Enter a title',
      'Importance must be between 1 and 5',
    ])
  })
})
