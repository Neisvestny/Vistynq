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

export interface UploadProgress {
  loaded: number
  total: number
  /** 0..100, или 0 если total неизвестен. */
  percent: number
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** JSON-объект (сериализуется) или FormData для multipart-запросов. */
  body?: unknown
  /** Добавлять Authorization: Bearer (по умолчанию true). */
  auth?: boolean
  query?: Record<string, string | number | boolean | null | undefined>
  headers?: Record<string, string>
  /** Прогресс загрузки тела запроса (multipart). Включает XHR-транспорт. */
  onUploadProgress?: (progress: UploadProgress) => void
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

async function execute<T>(
  path: string,
  options: RequestOptions,
  allowRefresh: boolean
): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers }
  let body: BodyInit | undefined

  if (options.body !== undefined) {
    if (typeof FormData !== 'undefined' && options.body instanceof FormData) {
      body = options.body
      // Content-Type для FormData выставляет браузер (с boundary).
    } else {
      headers['Content-Type'] = 'application/json'
      body = JSON.stringify(options.body)
    }
  }

  if (options.auth !== false && accessToken !== null) {
    headers['Authorization'] = `Bearer ${accessToken}`
  }

  const url = buildUrl(path, options.query)

  let res: Response
  try {
    res = options.onUploadProgress
      ? await xhrRequest(url, method, headers, body, options.onUploadProgress)
      : await fetchWithNetworkRetry(url, method, headers, body)
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

  if (res.status === 204 || res.status === 205) {
    return undefined as T
  }

  const text = await res.text()
  if (text === '') {
    return undefined as T
  }

  return JSON.parse(text) as T
}

function xhrRequest(
  url: string,
  method: string,
  headers: Record<string, string>,
  body: BodyInit | undefined,
  onUploadProgress: (progress: UploadProgress) => void
): Promise<Response> {
  return new Promise<Response>((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open(method, url)
    xhr.withCredentials = true
    xhr.responseType = 'text'

    for (const [key, value] of Object.entries(headers)) {
      xhr.setRequestHeader(key, value)
    }

    xhr.upload.onprogress = (event) => {
      const total = event.lengthComputable ? event.total : 0
      const loaded = event.loaded
      const percent = total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0
      onUploadProgress({ loaded, total, percent })
    }

    xhr.onerror = () => reject(new Error('Network error'))
    xhr.onabort = () => reject(new Error('Network error'))
    xhr.onload = () => {
      const responseHeaders = new Headers()
      const rawHeaders = xhr.getAllResponseHeaders()
      if (rawHeaders) {
        for (const line of rawHeaders.trim().split(/[\r\n]+/)) {
          const idx = line.indexOf(': ')
          if (idx > 0) responseHeaders.set(line.slice(0, idx), line.slice(idx + 2))
        }
      }
      resolve(
        new Response(xhr.responseText, {
          status: xhr.status,
          statusText: xhr.statusText,
          headers: responseHeaders,
        })
      )
    }

    xhr.send((body as XMLHttpRequestBodyInit | null | undefined) ?? null)
  })
}

/** GET-запросы повторяются один раз при сетевой ошибке. */
async function fetchWithNetworkRetry(
  url: string,
  method: string,
  headers: Record<string, string>,
  body?: BodyInit
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

function buildUrl(
  path: string,
  query?: Record<string, string | number | boolean | null | undefined>
): string {
  const url = `${API_BASE}${path}`
  if (!query) {
    return url
  }

  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null) {
      params.set(key, String(value))
    }
  }

  const qs = params.toString()

  return qs === '' ? url : `${url}?${qs}`
}
