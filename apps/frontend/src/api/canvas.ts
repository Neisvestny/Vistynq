/**
 * Канвасы и граф (docs/openapi.yaml tag: canvases).
 */
import { request } from './http'
import type { ArchiveSection, Canvas, GraphResponse, Note, Paginated, SearchHit } from './types'

export interface CreateCanvasPayload {
  title: string
}

export interface UpdateCanvasPayload {
  title?: string
}

export interface CanvasListParams {
  page?: number
  perPage?: number
}

export interface CanvasSearchParams {
  q: string
  page?: number
  perPage?: number
}

export interface CanvasArchiveParams {
  section: ArchiveSection
  page?: number
  perPage?: number
}

export interface PatchNodePositionPayload {
  x?: number
  y?: number
  width?: number
}

export const canvasApi = {
  /** Свои канвасы и те, к которым (или к заметкам которых) выдан доступ. */
  list(params: CanvasListParams = {}): Promise<Paginated<Canvas>> {
    return request<Paginated<Canvas>>('/canvases', {
      query: { page: params.page, perPage: params.perPage },
    })
  },

  create(payload: CreateCanvasPayload): Promise<Canvas> {
    return request<Canvas>('/canvases', { method: 'POST', body: payload })
  },

  /** Переименовать канвас (только owner). */
  update(id: string, payload: UpdateCanvasPayload): Promise<Canvas> {
    return request<Canvas>(`/canvases/${id}`, { method: 'PATCH', body: payload })
  },

  /** Удалить канвас с каскадом (только owner). */
  remove(id: string): Promise<void> {
    return request<void>(`/canvases/${id}`, { method: 'DELETE' })
  },

  /** Полная загрузка канваса: ноды, рёбра, теги, права. */
  graph(id: string): Promise<GraphResponse> {
    return request<GraphResponse>(`/canvases/${id}/graph`)
  },

  /** Сохранить позицию/размер ноды (дебаунс ~500 мс на стороне клиента). */
  patchNodePosition(
    canvasId: string,
    noteId: string,
    payload: PatchNodePositionPayload
  ): Promise<void> {
    return request<void>(`/canvases/${canvasId}/nodes/${noteId}`, {
      method: 'PATCH',
      body: payload,
    })
  },

  /** Поиск по заголовкам и тегам среди доступных заметок. */
  search(id: string, params: CanvasSearchParams): Promise<Paginated<SearchHit>> {
    return request<Paginated<SearchHit>>(`/canvases/${id}/search`, {
      query: { q: params.q, page: params.page, perPage: params.perPage },
    })
  },

  /** Архив канваса: неактуальные или удалённые заметки (≥editor). */
  archive(id: string, params: CanvasArchiveParams): Promise<Paginated<Note>> {
    return request<Paginated<Note>>(`/canvases/${id}/archive`, {
      query: { section: params.section, page: params.page, perPage: params.perPage },
    })
  },
}
