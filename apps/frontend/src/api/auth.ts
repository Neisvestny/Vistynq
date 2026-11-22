/**
 * Auth-endpointy (docs/api.md §Auth i profil). Tokeny verifikatsii/sbrosa
 * peredayutsya v tele zaprosa — ne v URL, chtoby ne popadat v logi (architecture.md §2).
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

export interface UpdateProfilePayload {
  displayName?: string
  email?: string
}

export const authApi = {
  login(payload: LoginPayload): Promise<AccessTokenResponse> {
    return request<AccessTokenResponse>('/auth/login', {
      method: 'POST',
      body: payload,
      auth: false,
    })
  },

  /** 201: pismo verifikatsii, refresh-kuka ne vydayotsya do verifikatsii. */
  register(payload: RegisterPayload): Promise<void> {
    return request<void>('/auth/register', { method: 'POST', body: payload, auth: false })
  },

  /** Rotatsiya cherez httpOnly-cookie; rezultat prokidyvaetsya v store cherez setOnTokenRefreshed. */
  refresh(): Promise<AccessTokenResponse> {
    return request<AccessTokenResponse>('/auth/refresh', { method: 'POST', auth: false })
  },

  logout(): Promise<void> {
    return request<void>('/auth/logout', { method: 'POST' })
  },

  me(): Promise<User> {
    return request<User>('/auth/me')
  },

  /** Smena imeni/email (email — cherez pendingEmail i pismo na novyy adres). */
  updateProfile(payload: UpdateProfilePayload): Promise<User> {
    return request<User>('/profile', { method: 'PATCH', body: payload })
  },

  verifyEmail(token: string): Promise<void> {
    return request<void>('/auth/verify-email', { method: 'POST', body: { token }, auth: false })
  },

  /** Vsegda 202 — bez utechki sushchestvovaniya email. */
  resendVerification(email: string): Promise<void> {
    return request<void>('/auth/resend-verification', {
      method: 'POST',
      body: { email },
      auth: false,
    })
  },

  /** Vsegda 202. */
  forgotPassword(email: string): Promise<void> {
    return request<void>('/auth/forgot-password', { method: 'POST', body: { email }, auth: false })
  },

  /** 204 + otzyv vsekh refresh-tokenov. */
  resetPassword(token: string, password: string): Promise<void> {
    return request<void>('/auth/reset-password', {
      method: 'POST',
      body: { token, password },
      auth: false,
    })
  },
}
