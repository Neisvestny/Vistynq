import { request } from './http'
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
  /** Создать заметку на канвасе (≥editor канваса). */
  create(canvasId: string, payload: CreateNotePayload = {}): Promise<Note> {
    return request<Note>(`/canvases/${canvasId}/notes`, { method: 'POST', body: payload })
  },

  /** Импорт .md/.txt (≤1 МБ) как новой заметки. */
  createFromText(canvasId: string, payload: CreateNoteFromTextPayload): Promise<Note> {
    const form = new FormData()
    form.append('file', payload.file)
    if (payload.title !== undefined) form.append('title', payload.title)
    if (payload.x !== undefined) form.append('x', String(payload.x))
    if (payload.y !== undefined) form.append('y', String(payload.y))
    return request<Note>(`/canvases/${canvasId}/notes/from-text`, { method: 'POST', body: form })
  },

  /** Создать заметку от родительской с прямой ссылкой. */
  derive(canvasId: string, payload: DeriveNotePayload): Promise<DeriveNoteResult> {
    return request<DeriveNoteResult>(`/canvases/${canvasId}/notes/derive`, {
      method: 'POST',
      body: payload,
    })
  },

  /** Заметка с телом и моей ролью. */
  get(id: string): Promise<NoteDetail> {
    return request<NoteDetail>(`/notes/${id}`)
  },

  /** Обновить манифест (заголовок, важность, актуальность, теги). */
  updateManifest(id: string, payload: UpdateNoteManifestPayload): Promise<NoteDetail> {
    return request<NoteDetail>(`/notes/${id}`, { method: 'PATCH', body: payload })
  },

  /** Сохранить markdown-тело (сервер перепарсит [[wikilinks]]). */
  updateBody(id: string, payload: UpdateNoteBodyPayload): Promise<NoteDetail> {
    return request<NoteDetail>(`/notes/${id}/body`, { method: 'PUT', body: payload })
  },

  /** Soft delete в архив «Удалённые». */
  remove(id: string): Promise<void> {
    return request<void>(`/notes/${id}`, { method: 'DELETE' })
  },

  /** Восстановить из архива (410 если стёрта навсегда). */
  restore(id: string): Promise<Note> {
    return request<Note>(`/notes/${id}/restore`, { method: 'POST' })
  },

  /** Стереть навсегда (tombstone). */
  purge(id: string): Promise<void> {
    return request<void>(`/notes/${id}/purge`, { method: 'DELETE' })
  },
}
