/**
 * HTTP-клиент API (docs/architecture.md §1, roadmap R04).
 *
 * - access-token живёт только в памяти модуля (не в localStorage);
 * - 401 → single-flight refresh (один inflight-промис на все параллельные
 *   запросы) → один повтор исходного запроса; повторный 401 → logout+редирект;
 * - сетевая ошибка на GET → один автоматический повтор.
 */
import type { AccessTokenResponse, ApiErrorBody } from './types'

export const API_BASE = '/api/v1'

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details?: Record<string, string[]>

  constructor(status: number, code: string, message: string, details?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
  }

  static network(message = 'Сервер недоступен — проверьте соединение'): ApiError {
    return new ApiError(0, 'NETWORK', message)
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** Сериализуется в JSON; для пустого тела не задавайте. */
  body?: unknown
  /** Добавлять Authorization: Bearer (по умолчанию true). */
  auth?: boolean
  query?: Record<string, string | number | undefined>
  headers?: Record<string, string>
}

/* ------------------------------------------------------------------ */
/* Токен и callbacks (регистрирует store — без циклических импортов)   */
/* ------------------------------------------------------------------ */

let accessToken: string | null = null

export function setAccessToken(token: string | null): void {
  accessToken = token
  if (token !== null) {
    // Новый токен — следующая «просрочка» снова имеет право уведомить один раз.
    sessionExpiredNotified = false
  }
}

export function getAccessToken(): string | null {
  return accessToken
}

let unauthorizedHandler: (() => void) | null = null

/** Повторный 401 после refresh → store делает logout + редирект + тост. */
export function setOnUnauthorized(handler: () => void): void {
  unauthorizedHandler = handler
}

/**
 * Защита от спама: N параллельных запросов, упавших в один 401, должны
 * вызвать unauthorizedHandler ровно один раз.
 */
let sessionExpiredNotified = false

let tokenRefreshedHandler: ((data: AccessTokenResponse) => void) | null = null

/** Успешный refresh → store обновляет user/token. */
export function setOnTokenRefreshed(handler: (data: AccessTokenResponse) => void): void {
  tokenRefreshedHandler = handler
}

/* ------------------------------------------------------------------ */
/* Single-flight refresh                                               */
/* ------------------------------------------------------------------ */

let refreshInflight: Promise<boolean> | null = null

function refreshSession(): Promise<boolean> {
  if (refreshInflight === null) {
    refreshInflight = (async () => {
      try {
        const res = await fetch(`${API_BASE}/auth/refresh`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        })
        if (!res.ok) {
          return false
        }
        const data = (await res.json()) as AccessTokenResponse
        setAccessToken(data.accessToken)
        tokenRefreshedHandler?.(data)

        return true
      } catch {
        return false
      } finally {
        refreshInflight = null
      }
    })()
  }

  return refreshInflight
}

/* ------------------------------------------------------------------ */
/* Request                                                             */
/* ------------------------------------------------------------------ */

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return execute<T>(path, options, true)
}

async function execute<T>(path: string, options: RequestOptions, allowRefresh: boolean): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers }
  let body: string | undefined

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  if (options.auth !== false && accessToken !== null) {
    headers['Authorization'] = `Bearer ${accessToken}`
  }

  const url = buildUrl(path, options.query)

  let res: Response
  try {
    res = await fetchWithNetworkRetry(url, method, headers, body)
  } catch {
    throw ApiError.network()
  }

  if (res.status === 401 && options.auth !== false) {
    if (allowRefresh && (await refreshSession())) {
      // Один повтор после успешного refresh.
      return execute<T>(path, options, false)
    }

    setAccessToken(null)
    if (!sessionExpiredNotified) {
      sessionExpiredNotified = true
      unauthorizedHandler?.()
    }
    throw await toApiError(res)
  }

  if (!res.ok) {
    throw await toApiError(res)
  }

  if (res.status === 204) {
    return undefined as T
  }

  return (await res.json()) as T
}

/** GET-запросы повторяются один раз при сетевой ошибке. */
async function fetchWithNetworkRetry(
  url: string,
  method: string,
  headers: Record<string, string>,
  body?: string,
): Promise<Response> {
  const init: RequestInit = { method, headers, credentials: 'same-origin' }
  if (body !== undefined) {
    init.body = body
  }

  try {
    return await fetch(url, init)
  } catch (error) {
    if (method !== 'GET') {
      throw error
    }

    return await fetch(url, init)
  }
}

async function toApiError(res: Response): Promise<ApiError> {
  let code = 'HTTP_' + res.status
  let message = `Ошибка сервера (${res.status})`
  let details: Record<string, string[]> | undefined

  try {
    const data = (await res.json()) as ApiErrorBody
    if (data?.error?.code) {
      code = data.error.code
      message = data.error.message || message
      details = data.error.details
    }
  } catch {
    // Не-JSON тело (например HTML от прокси) — оставляем дефолт.
  }

  return new ApiError(res.status, code, message, details)
}

function buildUrl(path: string, query?: Record<string, string | number | undefined>): string {
  const url = `${API_BASE}${path}`
  if (!query) {
    return url
  }

  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined) {
      params.set(key, String(value))
    }
  }

  const qs = params.toString()

  return qs === '' ? url : `${url}?${qs}`
}
