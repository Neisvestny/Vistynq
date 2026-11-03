/**
 * Канвасы и граф (docs/api.md §Канвасы, roadmap R14).
 */
import { request } from './http'
import type { Canvas, GraphResponse } from './types'

export interface CreateCanvasPayload {
  title: string
}

export const canvasApi = {
  /** Мои канвасы + канвасы, куда инвайтили (бейдж роли из access.myRole). */
  list(): Promise<{ items: Canvas[] }> {
    return request<{ items: Canvas[] }>('/canvases')
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
