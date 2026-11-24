/**
 * Auth store (Pinia). Access token is stored ONLY in memory -
 * not in localStorage or sessionStorage (docs/architecture.md §1).
 * After F5 session is restored via refresh cookie (restoreSession).
 */
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
// Router -> guards -> store cycle is safe: router is used only in runtime functions.
import { router } from '@/router'
import { authApi } from '@/api/auth'
import { tGlobal } from '@/i18n'
import { notify } from '@/api/notify'
import { safeRedirectPath } from '@/lib/redirect'
import { setAccessToken, setOnTokenRefreshed, setOnUnauthorized } from '@/api/http'
import type { AccessTokenResponse, User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  /** Reactive mirror of token from http.ts - for UI checks. */
  const accessToken = ref<string | null>(null)

  const isAuthenticated = computed(() => user.value !== null)
  const isVerified = computed(() => user.value?.emailVerified === true)

  function applyToken(data: AccessTokenResponse): void {
    accessToken.value = data.accessToken
    setAccessToken(data.accessToken)
    // login always returns user; refresh may not. Do not overwrite with undefined.
    if (data.user) {
      user.value = data.user
    }
  }

  function reset(): void {
    user.value = null
    accessToken.value = null
    setAccessToken(null)
  }

  async function login(email: string, password: string): Promise<void> {
    applyToken(await authApi.login({ email, password }))
  }

  async function register(email: string, password: string, displayName: string): Promise<void> {
    await authApi.register({ email, password, displayName })
    // Server cleared refresh cookie in 201 response - store also must not
    // keep user from previous session.
    reset()
  }

  /**
   * Best-effort logout without throwing: revoke cookie on server and in any
   * case clear local state. Needed for redirect to login after
   * email confirmation - cannot arrive there in another session.
   */
  async function clearSession(): Promise<void> {
    try {
      await authApi.logout()
    } catch {
      // Even on network error local session must be reset.
    } finally {
      reset()
    }
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      reset()
    }
  }

  async function fetchMe(): Promise<void> {
    user.value = await authApi.me()
  }

  /** Update profile (name/email). On email change server returns pendingEmail. */
  async function updateProfile(payload: { displayName?: string; email?: string }): Promise<void> {
    user.value = await authApi.updateProfile(payload)
  }

  function setAvatarUrl(avatarUrl: string | null): void {
    if (user.value) {
      user.value = { ...user.value, avatarUrl }
    }
  }

  /** Repeated 401 after failed refresh: logout + toast + redirect to /login. */
  function handleSessionExpired(): void {
    reset()
    notify({
      title: tGlobal('auth.sessionExpired'),
      description: tGlobal('auth.sessionExpiredDescription'),
      status: 'error',
    })
    const current = router.currentRoute?.value
    const redirect = current && current.name !== 'login' ? safeRedirectPath(current.fullPath) : null
    const target: { name: 'login'; query?: { redirect: string } } = { name: 'login' }
    if (redirect) target.query = { redirect }
    void router.push(target)
  }

  /* http.ts does not know about store - keep wiring here. */
  setOnUnauthorized(handleSessionExpired)
  setOnTokenRefreshed((data) => {
    accessToken.value = data.accessToken
    setAccessToken(data.accessToken)
    if (data.user) {
      user.value = data.user
    }
  })

  /* ---------------- bootstrap session restore ---------------- */

  let restored = false
  let restoreInflight: Promise<void> | null = null

  /**
   * Silent session restore via refresh cookie on app load.
   * 404/401 before auth endpoints appear (R09/R10) - just guest mode.
   */
  async function restoreSession(): Promise<void> {
    try {
      applyToken(await authApi.refresh())
    } catch {
      reset()
    } finally {
      restored = true
    }
  }

  /** Guards wait for restore to avoid redirecting logged-in user to /login after F5. */
  function ensureRestored(): Promise<void> {
    if (restored) {
      return Promise.resolve()
    }

    restoreInflight ??= restoreSession()

    return restoreInflight
  }

  /** For verify-email page: after success refresh user without login. */
  async function markEmailVerified(): Promise<void> {
    if (isAuthenticated.value) {
      await fetchMe()
    }
  }

  return {
    user,
    accessToken,
    isAuthenticated,
    isVerified,
    login,
    register,
    logout,
    clearSession,
    fetchMe,
    updateProfile,
    setAvatarUrl,
    ensureRestored,
    markEmailVerified,
    handleSessionExpired,
    reset,
  }
})
