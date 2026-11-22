import { request } from './http'
import type {
  AccessEntry,
  AccessLevel,
  CanvasAccessLevelResult,
  InviteRequest,
  InviteResult,
  NoteVisibility,
  RoleRequest,
} from './types'

export const accessApi = {
  /** Uchastniki i ozhidayushchie invayty kanvasa (owner). */
  listCanvasAccess(canvasId: string): Promise<AccessEntry[]> {
    return request<AccessEntry[]>(`/canvases/${canvasId}/access`)
  },

  /** Uroven dostupa kanvasa i publichnaya ssylka (owner). */
  setCanvasAccessLevel(canvasId: string, level: AccessLevel): Promise<CanvasAccessLevelResult> {
    return request<CanvasAccessLevelResult>(`/canvases/${canvasId}/access-level`, {
      method: 'PUT',
      body: { level },
    })
  },

  /** Vydat dostup ili priglasit po email na kanvas (owner). */
  inviteToCanvas(canvasId: string, payload: InviteRequest): Promise<InviteResult> {
    return request<InviteResult>(`/canvases/${canvasId}/access/invite`, {
      method: 'POST',
      body: payload,
    })
  },

  updateCanvasRole(canvasId: string, userId: string, payload: RoleRequest): Promise<void> {
    return request<void>(`/canvases/${canvasId}/access/${userId}`, {
      method: 'PATCH',
      body: payload,
    })
  },

  revokeCanvasAccess(canvasId: string, userId: string): Promise<void> {
    return request<void>(`/canvases/${canvasId}/access/${userId}`, { method: 'DELETE' })
  },

  revokeCanvasInvitation(canvasId: string, invitationId: string): Promise<void> {
    return request<void>(`/canvases/${canvasId}/invitations/${invitationId}`, { method: 'DELETE' })
  },

  /** Uchastniki i ozhidayushchie invayty zametki (owner/author). */
  listNoteAccess(noteId: string): Promise<AccessEntry[]> {
    return request<AccessEntry[]>(`/notes/${noteId}/access`)
  },

  /** Vidimost zametki (owner/author). */
  setNoteVisibility(
    noteId: string,
    visibility: NoteVisibility
  ): Promise<{ visibility: NoteVisibility }> {
    return request<{ visibility: NoteVisibility }>(`/notes/${noteId}/visibility`, {
      method: 'PUT',
      body: { visibility },
    })
  },

  inviteToNote(noteId: string, payload: InviteRequest): Promise<InviteResult> {
    return request<InviteResult>(`/notes/${noteId}/access/invite`, {
      method: 'POST',
      body: payload,
    })
  },

  updateNoteRole(noteId: string, userId: string, payload: RoleRequest): Promise<void> {
    return request<void>(`/notes/${noteId}/access/${userId}`, { method: 'PATCH', body: payload })
  },

  revokeNoteAccess(noteId: string, userId: string): Promise<void> {
    return request<void>(`/notes/${noteId}/access/${userId}`, { method: 'DELETE' })
  },

  revokeNoteInvitation(noteId: string, invitationId: string): Promise<void> {
    return request<void>(`/notes/${noteId}/invitations/${invitationId}`, { method: 'DELETE' })
  },
}
