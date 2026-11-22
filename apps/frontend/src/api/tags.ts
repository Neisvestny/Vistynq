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
  /** Spisok tegov kanvasa (≥viewer). */
  list(canvasId: string): Promise<Tag[]> {
    return request<Tag[]>(`/canvases/${canvasId}/tags`)
  },

  /** Sozdat teg kanvasa (≥editor). */
  create(canvasId: string, payload: CreateTagPayload): Promise<Tag> {
    return request<Tag>(`/canvases/${canvasId}/tags`, { method: 'POST', body: payload })
  },

  /** Pereimenovat/perekrasit teg. */
  update(id: string, payload: UpdateTagPayload): Promise<Tag> {
    return request<Tag>(`/tags/${id}`, { method: 'PATCH', body: payload })
  },

  /** Udalit teg so vsekh zametok. */
  remove(id: string): Promise<void> {
    return request<void>(`/tags/${id}`, { method: 'DELETE' })
  },
}
