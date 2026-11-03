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
export type AccessRole = 'owner' | 'editor' | 'viewer' | 'none'

/** docs/openapi.yaml → Canvas */
export interface Canvas {
  id: string
  title: string
  ownerId: string
  access: {
    level: AccessLevel
    publicSlug: string | null
    myRole: AccessRole
  }
  createdAt: string
  /** Только GET /canvases: незамеченные заметки; у графа/созданного канваса поля нет. */
  noteCount?: number
}

/** docs/openapi.yaml → GraphNode */
export interface GraphNode {
  id: string
  title: string
  importance: number
  isRelevant: boolean
  x: number
  y: number
  width: number
  deletedAt: string | null
  tags: string[]
  authorId: string
}

export type EdgeState = 'ok' | 'removed'

/** docs/openapi.yaml → GraphEdge; label заполнен только у state=removed. */
export interface GraphEdge {
  id: string
  type: 'direct' | 'indirect'
  from: string
  to: string | null
  state: EdgeState
  label: string | null
}

/** docs/openapi.yaml → Tag */
export interface GraphTag {
  id: string
  name: string
  color: string
}

/** docs/openapi.yaml → Graph: GET /canvases/{id}/graph */
export interface GraphResponse {
  canvas: Canvas
  nodes: GraphNode[]
  edges: GraphEdge[]
  tags: GraphTag[]
}
