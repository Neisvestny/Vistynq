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
  /** Новый email, ожидающий подтверждения (PATCH /profile). */
  pendingEmail?: string | null
}

/** Ответ login/refresh: access только в памяти, refresh — httpOnly cookie */
export interface AccessTokenResponse {
  /** Обязателен в login; в refresh может отсутствовать — store не затирает user на undefined. */
  user?: User
  accessToken: string
  accessTokenExpiresIn: number
}

export type AccessLevel = 'private' | 'public'
export type AccessRole = 'owner' | 'editor' | 'viewer' | 'none'
export type NoteVisibility = 'public' | 'private'
export type EdgeState = 'ok' | 'removed'
export type EdgeType = 'direct' | 'indirect'
export type InviteStatus = 'granted' | 'pending'
export type ArchiveSection = 'notRelevant' | 'deleted'
export type AttachmentKind = 'image' | 'audio' | 'avatar'

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
  updatedAt?: string
  /** Только GET /canvases: число доступных пользователю живых заметок. */
  noteCount?: number
}

/** Тег канваса (Graph.tags, /tags). */
export interface Tag {
  id: string
  name: string
  color: string
}

/** Обратная совместимость: alias для существующего кода. */
export type GraphTag = Tag

/** docs/openapi.yaml → GraphNode (фактический ответ бэкенда). */
export interface GraphNode {
  id: string
  title: string
  importance: number
  isRelevant: boolean
  visibility?: NoteVisibility
  x: number
  y: number
  width: number
  deletedAt: string | null
  /** Имена тегов заметки (для отображения на ноде). */
  tags: string[]
  authorId: string
}

/** docs/openapi.yaml → GraphEdge; label заполнен только у state=removed. */
export interface GraphEdge {
  id: string
  type: EdgeType
  from: string
  to: string | null
  state: EdgeState
  label: string | null
}

/** docs/openapi.yaml → Graph: GET /canvases/{id}/graph, /shared/canvases/{slug} */
export interface GraphResponse {
  canvas: Canvas
  nodes: GraphNode[]
  edges: GraphEdge[]
  tags: Tag[]
}

/** docs/openapi.yaml → PageMeta: пагинированные списки (R19). */
export interface Paginated<T> {
  items: T[]
  page: number
  perPage: number
  total: number
}

/** docs/openapi.yaml → Note */
export interface Note {
  id: string
  canvasId: string
  authorId: string
  title: string
  importance: number
  isRelevant: boolean
  visibility: NoteVisibility
  tagIds: string[]
  x: number
  y: number
  width: number
  createdAt: string
  updatedAt: string
  deletedAt?: string | null
}

/** docs/openapi.yaml → NoteDetail */
export interface NoteDetail extends Note {
  bodyMd: string
  myRole: 'owner' | 'editor' | 'viewer'
  attachments: Attachment[]
}

/** docs/openapi.yaml → SearchHit */
export interface SearchHit {
  id: string
  title: string
  importance: number
  matchedTag: string | null
}

/** docs/openapi.yaml → DirectLink */
export interface DirectLink {
  id: string
  fromNoteId: string
  toNoteId: string
}

/** docs/openapi.yaml → Attachment */
export interface Attachment {
  id: string
  noteId: string | null
  kind: AttachmentKind
  filename: string
  mime: string
  sizeBytes: number
  width: number | null
  height: number | null
  url: string
}

/** docs/openapi.yaml → AccessEntry */
export interface AccessEntry {
  userId: string | null
  email: string
  role: 'viewer' | 'editor'
  pending: boolean
  invitationId: string | null
}

/** docs/openapi.yaml → InviteRequest */
export interface InviteRequest {
  email: string
  role: 'viewer' | 'editor'
}

/** docs/openapi.yaml → InviteResult */
export interface InviteResult {
  status: InviteStatus
}

/** docs/openapi.yaml → RoleRequest */
export interface RoleRequest {
  role: 'viewer' | 'editor'
}

export interface CanvasAccessLevelResult {
  level: AccessLevel
  publicSlug: string | null
}

export interface AttachmentUrlResult {
  url: string
  expiresIn: number
}

export interface SharedNoteResponse {
  note: NoteDetail
  bodyHtml: string
}
