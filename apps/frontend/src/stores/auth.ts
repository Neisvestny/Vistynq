/**
 * Auth-store (Pinia). Access-token хранится ТОЛЬКО в памяти —
 * ни localStorage, ни sessionStorage (docs/architecture.md §1).
 * После F5 сессия восстанавливается через refresh-cookie (restoreSession).
 */
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
// Цикл router → guards → store безопасен: router используется только в рантайме-функциях.
import { router } from '@/router'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { setAccessToken, setOnTokenRefreshed, setOnUnauthorized } from '@/api/http'
import type { AccessTokenResponse, User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  /** Реактивное зеркало токена из http.ts — для UI-проверок. */
  const accessToken = ref<string | null>(null)

  const isAuthenticated = computed(() => user.value !== null)
  const isVerified = computed(() => user.value?.emailVerified === true)

  function applyToken(data: AccessTokenResponse): void {
    accessToken.value = data.accessToken
    setAccessToken(data.accessToken)
    // login всегда возвращает user; refresh — не обязательно. Не затираем на undefined.
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

  /** Повторный 401 после неудачного refresh: logout + тост + редирект на /login. */
  function handleSessionExpired(): void {
    reset()
    notify({
      title: 'Сессия истекла',
      description: 'Войдите снова, чтобы продолжить',
      status: 'error',
    })
    void router.push({ name: 'login' })
  }

  /* http.ts не знает про store — держим связь здесь. */
  setOnUnauthorized(handleSessionExpired)
  setOnTokenRefreshed((data) => {
    accessToken.value = data.accessToken
    setAccessToken(data.accessToken)
    if (data.user) {
      user.value = data.user
    }
  })

  /* ---------------- bootstrap-восстановление сессии ---------------- */

  let restored = false
  let restoreInflight: Promise<void> | null = null

  /**
   * Тихое восстановление сессии по refresh-cookie при загрузке приложения.
   * 404/401 до появления auth-эндпоинтов (R09/R10) — просто guest-режим.
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

  /** Guard-ы ждут восстановление, чтобы не редиректить залогиненного на /login после F5. */
  function ensureRestored(): Promise<void> {
    if (restored) {
      return Promise.resolve()
    }

    restoreInflight ??= restoreSession()

    return restoreInflight
  }

  /** Для страницы verify-email: после успеха обновляем user без логина. */
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
    fetchMe,
    ensureRestored,
    markEmailVerified,
    handleSessionExpired,
    reset,
  }
})
