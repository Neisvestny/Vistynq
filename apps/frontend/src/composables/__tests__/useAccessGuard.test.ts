import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/router', () => ({
  router: {
    push: vi.fn(),
    replace: vi.fn(),
  },
}))

import type { Canvas } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useCanvasStore } from '@/stores/canvas'
import { useAccessGuard } from '../useAccessGuard'

function setUser(id: string | null): void {
  const auth = useAuthStore()
  auth.user = id
    ? {
        id,
        email: `${id}@example.com`,
        displayName: id,
        emailVerified: true,
      }
    : null
}

function setCanvas(role: Canvas['access']['myRole'], ownerId = 'owner-1'): void {
  const canvas = useCanvasStore()
  canvas.canvas = {
    id: 'c1',
    title: 'C',
    ownerId,
    access: { level: 'private', myRole: role, publicSlug: null },
    createdAt: '2026-01-01T00:00:00.000Z',
  }
}

describe('useAccessGuard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('returns safe defaults without canvas/user', () => {
    const g = useAccessGuard()
    expect(g.userId.value).toBeNull()
    expect(g.canvasRole.value).toBe('none')
    expect(g.hasCanvasAccess.value).toBe(false)
    expect(g.canReadCanvas.value).toBe(false)
    expect(g.canEditCanvas.value).toBe(false)
    expect(g.canManageCanvasAccess.value).toBe(false)
    expect(g.canReadOnly.value).toBe(false)
    expect(g.canEditNote(null)).toBe(false)
    expect(g.canManageNoteAccess(null)).toBe(false)
    expect(g.canMoveResizeNode(null)).toBe(false)
    expect(g.canEditCurrentNote.value).toBe(false)
  })

  it.each([
    ['none', { has: false, read: false, edit: false, manage: false, readOnly: false }],
    ['viewer', { has: true, read: true, edit: false, manage: false, readOnly: true }],
    ['editor', { has: true, read: true, edit: true, manage: false, readOnly: false }],
    ['owner', { has: true, read: true, edit: true, manage: true, readOnly: false }],
  ] as const)('maps canvas role %s to permissions', (role, expected) => {
    setUser(role === 'owner' ? 'owner-1' : 'u-1')
    setCanvas(role, 'owner-1')
    const g = useAccessGuard()
    expect(g.canvasRole.value).toBe(role)
    expect(g.hasCanvasAccess.value).toBe(expected.has)
    expect(g.canReadCanvas.value).toBe(expected.read)
    expect(g.canEditCanvas.value).toBe(expected.edit)
    expect(g.canManageCanvasAccess.value).toBe(expected.manage)
    expect(g.canReadOnly.value).toBe(expected.readOnly)
  })

  it('allows manage access for canvas owner by id fallback', () => {
    setUser('owner-1')
    setCanvas('editor', 'owner-1')
    const g = useAccessGuard()
    expect(g.isOwner.value).toBe(false)
    expect(g.canManageCanvasAccess.value).toBe(true)
  })

  describe('canEditNote', () => {
    it('respects explicit myRole first', () => {
      setUser('u-2')
      setCanvas('viewer', 'owner-1')
      const g = useAccessGuard()
      expect(g.canEditNote({ myRole: 'owner' })).toBe(true)
      expect(g.canEditNote({ myRole: 'editor' })).toBe(true)
      expect(g.canEditNote({ myRole: 'viewer' })).toBe(false)
      expect(g.canEditNote({ myRole: 'none' })).toBe(false)
    })

    it('falls back to author and canvas role when myRole absent', () => {
      setUser('u-2')
      setCanvas('viewer', 'owner-1')
      const g = useAccessGuard()
      expect(g.canEditNote({ authorId: 'u-2' })).toBe(true)
      expect(g.canEditNote({ authorId: 'someone-else' })).toBe(false)

      setCanvas('editor', 'owner-1')
      const g2 = useAccessGuard()
      expect(g2.canEditNote({ authorId: 'someone-else' })).toBe(true)

      setCanvas('owner', 'owner-1')
      const g3 = useAccessGuard()
      expect(g3.canEditNote({ authorId: 'someone-else' })).toBe(true)
    })

    it('returns false for null/undefined target', () => {
      setCanvas('editor', 'owner-1')
      const g = useAccessGuard()
      expect(g.canEditNote(null)).toBe(false)
      expect(g.canEditNote(undefined)).toBe(false)
    })
  })

  describe('canManageNoteAccess', () => {
    it('allows owner-note, author, canvas owner and owner-by-id', () => {
      setUser('u-2')
      setCanvas('viewer', 'owner-1')
      const g = useAccessGuard()
      expect(g.canManageNoteAccess({ myRole: 'owner' })).toBe(true)
      expect(g.canManageNoteAccess({ authorId: 'u-2' })).toBe(true)
      expect(g.canManageNoteAccess({ authorId: 'other' })).toBe(false)

      setCanvas('owner', 'owner-1')
      expect(useAccessGuard().canManageNoteAccess({ authorId: 'other' })).toBe(true)

      setUser('owner-1')
      setCanvas('editor', 'owner-1')
      expect(useAccessGuard().canManageNoteAccess({ authorId: 'other' })).toBe(true)
    })

    it('denies editor canvas without ownership/author', () => {
      setUser('u-2')
      setCanvas('editor', 'owner-1')
      const g = useAccessGuard()
      expect(g.canManageNoteAccess({ authorId: 'other', myRole: 'editor' })).toBe(false)
    })

    it('returns false for missing target', () => {
      const g = useAccessGuard()
      expect(g.canManageNoteAccess(null)).toBe(false)
      expect(g.canManageNoteAccess(undefined)).toBe(false)
    })
  })

  describe('canMoveResizeNode', () => {
    it('allows canvas editors/owners', () => {
      setUser('u-2')
      setCanvas('editor', 'owner-1')
      expect(useAccessGuard().canMoveResizeNode({ authorId: 'other' })).toBe(true)
      setCanvas('owner', 'owner-1')
      expect(useAccessGuard().canMoveResizeNode({ authorId: 'other' })).toBe(true)
    })

    it('allows author when canvas is viewer, denies others', () => {
      setUser('u-2')
      setCanvas('viewer', 'owner-1')
      const g = useAccessGuard()
      expect(g.canMoveResizeNode({ authorId: 'u-2' })).toBe(true)
      expect(g.canMoveResizeNode({ authorId: 'other' })).toBe(false)
      expect(g.canMoveResizeNode(null)).toBe(false)
    })
  })

  it('supports reactive note arg and exposes canEditCurrentNote', () => {
    setUser('u-2')
    setCanvas('viewer', 'owner-1')
    const note = ref<{ authorId?: string; myRole?: 'viewer' | 'editor' } | null>({
      authorId: 'other',
      myRole: 'viewer',
    })
    const g = useAccessGuard(note)

    expect(g.canEditCurrentNote.value).toBe(false)
    note.value = { authorId: 'u-2' }
    expect(g.canEditCurrentNote.value).toBe(true)
    note.value = { authorId: 'other', myRole: 'editor' }
    expect(g.canEditCurrentNote.value).toBe(true)
  })

  it('supports getter note arg', () => {
    setUser('u-2')
    setCanvas('viewer', 'owner-1')
    const note = ref<{ authorId: string } | null>({ authorId: 'other' })
    const g = useAccessGuard(() => note.value)
    expect(g.canEditCurrentNote.value).toBe(false)
    note.value = { authorId: 'u-2' }
    expect(g.canEditCurrentNote.value).toBe(true)
  })

  it('handles missing user/canvas gracefully', () => {
    setUser(null)
    setCanvas('viewer', 'owner-1')
    const g = useAccessGuard()
    expect(g.userId.value).toBeNull()
    expect(g.canManageCanvasAccess.value).toBe(false)
    expect(g.canEditNote({ authorId: 'u-2' })).toBe(false)
  })
})
