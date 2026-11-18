import { request } from './http'
import type { Tag } from './types'

export interface CreateTagPayload {
  name: string
  color?: string
}

export interface UpdateTagPayload {
  name?: string
  color?: string
}

export const tagsApi = {
  /** Список тегов канваса (≥viewer). */
  list(canvasId: string): Promise<Tag[]> {
    return request<Tag[]>(`/canvases/${canvasId}/tags`)
  },

  /** Создать тег канваса (≥editor). */
  create(canvasId: string, payload: CreateTagPayload): Promise<Tag> {
    return request<Tag>(`/canvases/${canvasId}/tags`, { method: 'POST', body: payload })
  },

  /** Переименовать/перекрасить тег. */
  update(id: string, payload: UpdateTagPayload): Promise<Tag> {
    return request<Tag>(`/tags/${id}`, { method: 'PATCH', body: payload })
  },

  /** Удалить тег со всех заметок. */
  remove(id: string): Promise<void> {
    return request<void>(`/tags/${id}`, { method: 'DELETE' })
  },
}
