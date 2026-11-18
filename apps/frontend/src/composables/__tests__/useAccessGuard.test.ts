import { describe, expect, it, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/router', () => ({
  router: {
    push: vi.fn(),
    replace: vi.fn(),
  },
}))

import { useAuthStore } from '@/stores/auth'
import { useCanvasStore } from '@/stores/canvas'
import type { Canvas } from '@/api/types'
import { useAccessGuard } from '../useAccessGuard'

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

  it('определяет права канваса по роли', () => {
    setCanvas('editor')
    const g = useAccessGuard()
    expect(g.isEditor.value).toBe(true)
    expect(g.canEditCanvas.value).toBe(true)
    expect(g.canManageCanvasAccess.value).toBe(false)
    expect(g.canReadOnly.value).toBe(false)
  })

  it('owner может управлять доступом; viewer только чтение', () => {
    const auth = useAuthStore()
    auth.user = {
      id: 'owner-1',
      email: 'o@example.com',
      displayName: 'Owner',
      emailVerified: true,
    }

    setCanvas('owner', 'owner-1')
    let g = useAccessGuard()
    expect(g.canManageCanvasAccess.value).toBe(true)
    expect(g.canEditCanvas.value).toBe(true)

    setCanvas('viewer', 'owner-1')
    g = useAccessGuard()
    expect(g.canReadOnly.value).toBe(true)
    expect(g.canEditCanvas.value).toBe(false)
  })

  it('canEditNote учитывает myRole, автора и роль канваса', () => {
    const auth = useAuthStore()
    auth.user = {
      id: 'u-2',
      email: 'u2@example.com',
      displayName: 'U2',
      emailVerified: true,
    }
    setCanvas('viewer', 'owner-1')

    const g = useAccessGuard()
    expect(g.canEditNote({ myRole: 'viewer' })).toBe(false)
    expect(g.canEditNote({ myRole: 'editor' })).toBe(true)
    expect(g.canEditNote({ authorId: 'u-2' })).toBe(true)

    setCanvas('editor', 'owner-1')
    const g2 = useAccessGuard()
    expect(g2.canEditNote({ authorId: 'someone-else' })).toBe(true)
  })
})
