import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const httpMocks = vi.hoisted(() => ({
  setAccessToken: vi.fn(),
  setOnUnauthorized: vi.fn(),
  setOnTokenRefreshed: vi.fn(),
  getAccessToken: vi.fn(() => null),
}))

vi.mock('@/router', () => ({ router: { push: vi.fn() } }))
vi.mock('@/api/notify', () => ({ notify: vi.fn() }))
vi.mock('@/api/http', () => httpMocks)
vi.mock('@/api/auth', () => ({
  authApi: {
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    refresh: vi.fn(),
    me: vi.fn(),
    updateProfile: vi.fn(),
  },
}))

import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { router } from '@/router'

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

function getUnauthorizedHandler() {
  return httpMocks.setOnUnauthorized.mock.calls.at(-1)?.[0] as (() => void) | undefined
}
function getTokenRefreshedHandler() {
  return httpMocks.setOnTokenRefreshed.mock.calls.at(-1)?.[0] as
    ((d: { accessToken: string; accessTokenExpiresIn: number; user?: unknown }) => void) | undefined
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('login stores user/token and mirrors token in http', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)
    const auth = useAuthStore()
    await auth.login('smoke@vistynq.test', 'password-123')

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.isVerified).toBe(true)
    expect(auth.accessToken).toBe('access-token-abc')
    expect(httpMocks.setAccessToken).toHaveBeenCalledWith('access-token-abc')
  })

  it('register resets session and calls api', async () => {
    vi.mocked(authApi.refresh).mockResolvedValue(tokenResponse)
    vi.mocked(authApi.register).mockResolvedValue(undefined)
    const auth = useAuthStore()
    await auth.ensureRestored()
    await auth.register('new@vistynq.test', 'password-123', 'New')

    expect(authApi.register).toHaveBeenCalledWith({
      email: 'new@vistynq.test',
      password: 'password-123',
      displayName: 'New',
    })
    expect(auth.user).toBeNull()
    expect(auth.accessToken).toBeNull()
  })

  it('logout resets session even if request fails', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)
    vi.mocked(authApi.logout).mockRejectedValue(new Error('network down'))
    const auth = useAuthStore()
    await auth.login('smoke@vistynq.test', 'password-123')
    await expect(auth.logout()).rejects.toThrow('network down')
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.accessToken).toBeNull()
  })

  it('clearSession is best-effort and always resets', async () => {
    vi.mocked(authApi.refresh).mockResolvedValue(tokenResponse)
    vi.mocked(authApi.logout).mockRejectedValue(new Error('network down'))
    const auth = useAuthStore()
    await auth.ensureRestored()
    await expect(auth.clearSession()).resolves.toBeUndefined()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.accessToken).toBeNull()
  })

  it('restoreSession handles success once and failure as guest', async () => {
    vi.mocked(authApi.refresh).mockResolvedValue(tokenResponse)
    const auth = useAuthStore()
    await auth.ensureRestored()
    await auth.ensureRestored()
    expect(auth.isAuthenticated).toBe(true)
    expect(authApi.refresh).toHaveBeenCalledTimes(1)

    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(authApi.refresh).mockRejectedValue(new Error('401'))
    const auth2 = useAuthStore()
    await auth2.ensureRestored()
    expect(auth2.isAuthenticated).toBe(false)
  })

  it('fetchMe and updateProfile update user; setAvatarUrl updates avatar', async () => {
    const auth = useAuthStore()
    vi.mocked(authApi.me).mockResolvedValue(user)
    await auth.fetchMe()
    expect(auth.user).toEqual(user)

    vi.mocked(authApi.updateProfile).mockResolvedValue({ ...user, displayName: 'New' })
    await auth.updateProfile({ displayName: 'New' })
    expect(auth.user?.displayName).toBe('New')

    auth.setAvatarUrl('https://img/a.png')
    expect(auth.user?.avatarUrl).toBe('https://img/a.png')
    auth.setAvatarUrl(null)
    expect(auth.user?.avatarUrl).toBeNull()
  })

  it('setAvatarUrl is no-op without user', () => {
    const auth = useAuthStore()
    auth.setAvatarUrl('x')
    expect(auth.user).toBeNull()
  })

  it('handleSessionExpired resets, notifies and redirects to login', () => {
    const auth = useAuthStore()
    auth.accessToken = 'x'
    auth.user = user
    auth.handleSessionExpired()
    expect(auth.user).toBeNull()
    expect(auth.accessToken).toBeNull()
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ status: 'error' }))
    expect(router.push).toHaveBeenCalledWith({ name: 'login' })
  })

  it('markEmailVerified fetches me only when authenticated', async () => {
    const auth = useAuthStore()
    vi.mocked(authApi.me).mockResolvedValue(user)
    await auth.markEmailVerified()
    expect(authApi.me).not.toHaveBeenCalled()

    auth.user = { ...user, emailVerified: false }
    await auth.markEmailVerified()
    expect(authApi.me).toHaveBeenCalledTimes(1)
  })

  it('registers http callbacks; token refreshed callback updates state', () => {
    const auth = useAuthStore()
    expect(getUnauthorizedHandler()).toBeTypeOf('function')
    expect(getTokenRefreshedHandler()).toBeTypeOf('function')

    getTokenRefreshedHandler()?.({
      accessToken: 'new-token',
      accessTokenExpiresIn: 900,
      user: { ...user, displayName: 'U' },
    })
    expect(auth.accessToken).toBe('new-token')
    expect(auth.user?.displayName).toBe('U')

    getTokenRefreshedHandler()?.({ accessToken: 'newer', accessTokenExpiresIn: 900 })
    expect(auth.accessToken).toBe('newer')
    expect(auth.user?.displayName).toBe('U')
  })

  it('reset clears local session state', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)
    const auth = useAuthStore()
    await auth.login('smoke@vistynq.test', 'password-123')
    auth.reset()
    expect(auth.user).toBeNull()
    expect(auth.accessToken).toBeNull()
  })
})
