import { request } from './http'
import type { DirectLink } from './types'

export interface CreateDirectLinkPayload {
  fromNoteId: string
  toNoteId: string
}

export const linksApi = {
  /** Создать прямую ссылку между заметками одного канваса. */
  createDirect(payload: CreateDirectLinkPayload): Promise<DirectLink> {
    return request<DirectLink>('/links/direct', { method: 'POST', body: payload })
  },

  /** Удалить прямую ссылку. */
  removeDirect(id: string): Promise<void> {
    return request<void>(`/links/direct/${id}`, { method: 'DELETE' })
  },

  /** Развернуть направление прямой ссылки. */
  reverseDirect(id: string): Promise<DirectLink> {
    return request<DirectLink>(`/links/direct/${id}/reverse`, { method: 'POST' })
  },
}
