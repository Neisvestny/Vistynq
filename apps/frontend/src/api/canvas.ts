/**
 * Канвасы и граф (docs/api.md §Канвасы, roadmap R14, R19 — пагинация).
 */
import { request } from './http'
import type { Canvas, GraphResponse, Paginated } from './types'

export interface CreateCanvasPayload {
  title: string
}

export interface CanvasListParams {
  page?: number
  perPage?: number
}

export const canvasApi = {
  /** Мои канвасы + канвасы, куда инвайтили (бейдж роли из access.myRole). */
  list(params: CanvasListParams = {}): Promise<Paginated<Canvas>> {
    const search = new URLSearchParams()
    if (params.page != null) search.set('page', String(params.page))
    if (params.perPage != null) search.set('perPage', String(params.perPage))
    const query = search.toString()

    return request<Paginated<Canvas>>(`/canvases${query ? `?${query}` : ''}`)
  },

  /** 201; требует подтверждённого email (403 EMAIL_NOT_VERIFIED). */
  create(payload: CreateCanvasPayload): Promise<Canvas> {
    return request<Canvas>('/canvases', { method: 'POST', body: payload })
  },

  /** Полная загрузка канваса: ноды, рёбра, теги, права. */
  graph(id: string): Promise<GraphResponse> {
    return request<GraphResponse>(`/canvases/${id}/graph`)
  },
}
