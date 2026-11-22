import { request } from './http'
import type { DirectLink } from './types'

export interface CreateDirectLinkPayload {
  fromNoteId: string
  toNoteId: string
}

export const linksApi = {
  /** Sozdat pryamuyu ssylku mezhdu zametkami odnogo kanvasa. */
  createDirect(payload: CreateDirectLinkPayload): Promise<DirectLink> {
    return request<DirectLink>('/links/direct', { method: 'POST', body: payload })
  },

  /** Udalit pryamuyu ssylku. */
  removeDirect(id: string): Promise<void> {
    return request<void>(`/links/direct/${id}`, { method: 'DELETE' })
  },

  /** Razvernut napravlenie pryamoy ssylki. */
  reverseDirect(id: string): Promise<DirectLink> {
    return request<DirectLink>(`/links/direct/${id}/reverse`, { method: 'POST' })
  },
}
