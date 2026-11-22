import { request } from './http'
import type { GraphResponse, SharedNoteResponse } from './types'

export const sharedApi = {
  /** Publichnyy read-only graf kanvasa po slug. */
  canvas(slug: string): Promise<GraphResponse> {
    return request<GraphResponse>(`/shared/canvases/${slug}`, { auth: false })
  },

  /** Publichnaya read-only zametka publichnogo kanvasa. */
  note(slug: string, noteId: string): Promise<SharedNoteResponse> {
    return request<SharedNoteResponse>(`/shared/canvases/${slug}/notes/${noteId}`, { auth: false })
  },
}
