import { request, type UploadProgress } from './http'
import type { DirectLink, Note, NoteDetail } from './types'

export interface CreateNotePayload {
  title?: string
  x?: number
  y?: number
}

export interface CreateNoteFromTextPayload {
  file: File
  title?: string
  x?: number
  y?: number
  onUploadProgress?: (progress: UploadProgress) => void
}

export interface UpdateNoteManifestPayload {
  title?: string
  importance?: number
  isRelevant?: boolean
  tagIds?: string[]
}

export interface UpdateNoteBodyPayload {
  bodyMd: string
}

export interface DeriveNotePayload {
  parentNoteId: string
  x?: number
  y?: number
}

export interface DeriveNoteResult {
  note: Note
  link: DirectLink
}

export const notesApi = {
  /** Sozdat zametku na kanvase (≥editor kanvasa). */
  create(canvasId: string, payload: CreateNotePayload = {}): Promise<Note> {
    return request<Note>(`/canvases/${canvasId}/notes`, { method: 'POST', body: payload })
  },

  /** Import .md/.txt (≤1 MB) kak novoy zametki. */
  createFromText(canvasId: string, payload: CreateNoteFromTextPayload): Promise<Note> {
    const form = new FormData()
    form.append('file', payload.file)
    if (payload.title !== undefined) form.append('title', payload.title)
    if (payload.x !== undefined) form.append('x', String(payload.x))
    if (payload.y !== undefined) form.append('y', String(payload.y))
    return request<Note>(`/canvases/${canvasId}/notes/from-text`, {
      method: 'POST',
      body: form,
      onUploadProgress: payload.onUploadProgress,
    })
  },

  /** Sozdat zametku ot roditelskoy s pryamoy ssylkoy. */
  derive(canvasId: string, payload: DeriveNotePayload): Promise<DeriveNoteResult> {
    return request<DeriveNoteResult>(`/canvases/${canvasId}/notes/derive`, {
      method: 'POST',
      body: payload,
    })
  },

  /** Zametka s telom i moey rolyu. */
  get(id: string): Promise<NoteDetail> {
    return request<NoteDetail>(`/notes/${id}`)
  },

  /** Obnovit manifest (zagolovok, vazhnost, aktualnost, tegi). */
  updateManifest(id: string, payload: UpdateNoteManifestPayload): Promise<NoteDetail> {
    return request<NoteDetail>(`/notes/${id}`, { method: 'PATCH', body: payload })
  },

  /** Sokhranit markdown-telo (server pereparsit [[wikilinks]]). */
  updateBody(id: string, payload: UpdateNoteBodyPayload): Promise<NoteDetail> {
    return request<NoteDetail>(`/notes/${id}/body`, { method: 'PUT', body: payload })
  },

  /** Soft delete v arkhiv «Udalyonnye». */
  remove(id: string): Promise<void> {
    return request<void>(`/notes/${id}`, { method: 'DELETE' })
  },

  /** Vosstanovit iz arkhiva (410 esli styorta navsegda). */
  restore(id: string): Promise<Note> {
    return request<Note>(`/notes/${id}/restore`, { method: 'POST' })
  },

  /** Steret navsegda (tombstone). */
  purge(id: string): Promise<void> {
    return request<void>(`/notes/${id}/purge`, { method: 'DELETE' })
  },
}
