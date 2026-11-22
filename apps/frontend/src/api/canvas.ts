/**
 * Kanvasy i graf (docs/openapi.yaml tag: canvases).
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
  /** Svoi kanvasy i te, k kotorym (ili k zametkam kotorykh) vydan dostup. */
  list(params: CanvasListParams = {}): Promise<Paginated<Canvas>> {
    return request<Paginated<Canvas>>('/canvases', {
      query: { page: params.page, perPage: params.perPage },
    })
  },

  create(payload: CreateCanvasPayload): Promise<Canvas> {
    return request<Canvas>('/canvases', { method: 'POST', body: payload })
  },

  /** Pereimenovat kanvas (tolko owner). */
  update(id: string, payload: UpdateCanvasPayload): Promise<Canvas> {
    return request<Canvas>(`/canvases/${id}`, { method: 'PATCH', body: payload })
  },

  /** Udalit kanvas s kaskadom (tolko owner). */
  remove(id: string): Promise<void> {
    return request<void>(`/canvases/${id}`, { method: 'DELETE' })
  },

  /** Polnaya zagruzka kanvasa: nody, ryobra, tegi, prava. */
  graph(id: string): Promise<GraphResponse> {
    return request<GraphResponse>(`/canvases/${id}/graph`)
  },

  /** Sokhranit pozitsiyu/razmer nody (debauns ~500 ms na storone klienta). */
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

  /** Poisk po zagolovkam i tegam sredi dostupnykh zametok. */
  search(id: string, params: CanvasSearchParams): Promise<Paginated<SearchHit>> {
    return request<Paginated<SearchHit>>(`/canvases/${id}/search`, {
      query: { q: params.q, page: params.page, perPage: params.perPage },
    })
  },

  /** Arkhiv kanvasa: neaktualnye ili udalyonnye zametki (≥editor). */
  archive(id: string, params: CanvasArchiveParams): Promise<Paginated<Note>> {
    return request<Paginated<Note>>(`/canvases/${id}/archive`, {
      query: { section: params.section, page: params.page, perPage: params.perPage },
    })
  },
}
