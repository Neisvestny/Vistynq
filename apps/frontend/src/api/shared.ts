import { request } from './http'
import type { GraphResponse, SharedNoteResponse } from './types'

export const sharedApi = {
  /** Публичный read-only граф канваса по slug. */
  canvas(slug: string): Promise<GraphResponse> {
    return request<GraphResponse>(`/shared/canvases/${slug}`, { auth: false })
  },

  /** Публичная read-only заметка публичного канваса. */
  note(slug: string, noteId: string): Promise<SharedNoteResponse> {
    return request<SharedNoteResponse>(`/shared/canvases/${slug}/notes/${noteId}`, { auth: false })
  },
}
