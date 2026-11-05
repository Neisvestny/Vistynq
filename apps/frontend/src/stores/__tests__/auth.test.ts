import { describe, expect, it, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// router тянет .vue- layouts/views и createWebHistory (нужен window) — мокаем целиком.
vi.mock('@/router', () => ({ router: { push: vi.fn() } }))
vi.mock('@/api/notify', () => ({ notify: vi.fn() }))
vi.mock('@/api/auth', () => ({
  authApi: {
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    refresh: vi.fn(),
    me: vi.fn(),
  },
}))

import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import { getAccessToken } from '@/api/http'

const user = {
  id: 'u-1',
  email: 'smoke@vistynq.test',
  displayName: 'Smoke',
  emailVerified: true,
}

const tokenResponse = {
  accessToken: 'access-token-abc',
  accessTokenExpiresIn: 900,
  user,
}

describe('auth store (smoke, R16 → детали в R21)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(authApi.login).mockReset()
    vi.mocked(authApi.logout).mockReset()
    vi.mocked(authApi.refresh).mockReset()
  })

  it('login заполняет user, accessToken и зеркало в http', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)

    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(false)

    await auth.login('smoke@vistynq.test', 'password-123')

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.isVerified).toBe(true)
    expect(auth.accessToken).toBe('access-token-abc')
    expect(getAccessToken()).toBe('access-token-abc')
  })

  it('logout сбрасывает сессию, даже если запрос упал', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)
    vi.mocked(authApi.logout).mockRejectedValue(new Error('network down'))

    const auth = useAuthStore()
    await auth.login('smoke@vistynq.test', 'password-123')

    await expect(auth.logout()).rejects.toThrow('network down')
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.accessToken).toBeNull()
    expect(getAccessToken()).toBeNull()
  })

  it('restoreSession: неудачный refresh → guest-режим (без исключения)', async () => {
    vi.mocked(authApi.refresh).mockRejectedValue(new Error('401'))

    const auth = useAuthStore()
    await auth.ensureRestored()

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.accessToken).toBeNull()
  })

  it('restoreSession: успешный refresh восстанавливает сессию один раз', async () => {
    vi.mocked(authApi.refresh).mockResolvedValue(tokenResponse)

    const auth = useAuthStore()
    await auth.ensureRestored()
    await auth.ensureRestored()

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.accessToken).toBe('access-token-abc')
    expect(authApi.refresh).toHaveBeenCalledTimes(1)
  })
})
