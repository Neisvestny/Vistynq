/**
 * DTO по docs/openapi.yaml. Общие типы контракта API.
 */

/** Ошибка в формате docs/api.md: {error: {code, message, details?}} */
export interface ApiErrorBody {
  error: {
    code: string
    message: string
    details?: Record<string, string[]>
  }
}

/** GET /auth/me и элемент AccessTokenResponse */
export interface User {
  id: string
  email: string
  displayName: string
  avatarUrl?: string | null
  emailVerified: boolean
}

/** Ответ login/refresh: access только в памяти, refresh — httpOnly cookie */
export interface AccessTokenResponse {
  /** Обязателен в login; в refresh может отсутствовать — store не затирает user на undefined. */
  user?: User
  accessToken: string
  accessTokenExpiresIn: number
}

export type AccessLevel = 'private' | 'specific' | 'public_link'
export type AccessRole = 'owner' | 'editor' | 'viewer'

export interface Canvas {
  id: string
  title: string
  ownerId: string
  accessLevel: AccessLevel
  publicSlug?: string | null
  createdAt: string
  updatedAt: string
}
