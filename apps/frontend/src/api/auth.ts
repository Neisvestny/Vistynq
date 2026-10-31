/**
 * Auth-эндпоинты (docs/api.md §Auth и профиль). Токены верификации/sброса
 * передаются в теле запроса — не в URL, чтобы не попадать в логи (architecture.md §2).
 */
import { request } from './http'
import type { AccessTokenResponse, User } from './types'

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload extends LoginPayload {
  displayName: string
}

export const authApi = {
  login(payload: LoginPayload): Promise<AccessTokenResponse> {
    return request<AccessTokenResponse>('/auth/login', {
      method: 'POST',
      body: payload,
      auth: false,
    })
  },

  /** 201: письмо верификации, refresh-кука не выдаётся до верификации. */
  register(payload: RegisterPayload): Promise<void> {
    return request<void>('/auth/register', { method: 'POST', body: payload, auth: false })
  },

  /** Ротация через httpOnly-cookie; результат прокидывается в store через setOnTokenRefreshed. */
  refresh(): Promise<AccessTokenResponse> {
    return request<AccessTokenResponse>('/auth/refresh', { method: 'POST', auth: false })
  },

  logout(): Promise<void> {
    return request<void>('/auth/logout', { method: 'POST' })
  },

  me(): Promise<User> {
    return request<User>('/auth/me')
  },

  verifyEmail(token: string): Promise<void> {
    return request<void>('/auth/verify-email', { method: 'POST', body: { token }, auth: false })
  },

  /** Всегда 202 — без утечки существования email. */
  resendVerification(email: string): Promise<void> {
    return request<void>('/auth/resend-verification', {
      method: 'POST',
      body: { email },
      auth: false,
    })
  },

  /** Всегда 202. */
  forgotPassword(email: string): Promise<void> {
    return request<void>('/auth/forgot-password', { method: 'POST', body: { email }, auth: false })
  },

  /** 204 + отзыв всех refresh-токенов. */
  resetPassword(token: string, password: string): Promise<void> {
    return request<void>('/auth/reset-password', {
      method: 'POST',
      body: { token, password },
      auth: false,
    })
  },
}
