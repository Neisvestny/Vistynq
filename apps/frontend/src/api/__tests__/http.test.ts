/**
 * Тесты http-клиента (roadmap R04): Bearer, single-flight refresh при
 * параллельных 401, повтор запроса, повторный 401 = logout-callback,
 * auth:false без refresh, сетевой retry GET.
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'

import {
  request,
  ApiError,
  setAccessToken,
  setOnUnauthorized,
  setOnTokenRefreshed,
} from '@/api/http'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function error401(): Response {
  return jsonResponse(401, { error: { code: 'UNAUTHORIZED', message: 'Требуется вход' } })
}

const fetchMock = vi.fn<typeof fetch>()

function callHeaders(index: number): Record<string, string> {
  const init = fetchMock.mock.calls[index]?.[1]
  return (init?.headers ?? {}) as Record<string, string>
}

describe('http-клиент (R04)', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    // setAccessToken(non-null) сбрасывает sessionExpiredNotified, затем чистим токен.
    setAccessToken('seed')
    setAccessToken(null)
    setOnUnauthorized(vi.fn())
    setOnTokenRefreshed(vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('GET подставляет Authorization: Bearer; auth:false — без заголовка', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, { ok: true }))
    setAccessToken('t-1')

    await request('/me')
    await request('/auth/login', { method: 'POST', auth: false, body: { email: 'a@b.c' } })

    expect(callHeaders(0).Authorization).toBe('Bearer t-1')
    expect(callHeaders(1).Authorization).toBeUndefined()
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('критерий: 3 параллельных 401 → один refresh, повторы с новым токеном', async () => {
    setAccessToken('old-token')
    let apiCalls = 0
    fetchMock.mockImplementation(async (url) => {
      if (String(url).endsWith('/auth/refresh')) {
        return jsonResponse(200, { accessToken: 'new-token', accessTokenExpiresIn: 900 })
      }
      apiCalls += 1
      // первая волна — все три 401, повторы — 200
      return apiCalls <= 3 ? error401() : jsonResponse(200, { items: [] })
    })

    const results = await Promise.all([
      request<{ items: unknown[] }>('/canvases'),
      request<{ items: unknown[] }>('/canvases/members'),
      request<{ items: unknown[] }>('/canvases/invites'),
    ])

    // 3 запроса + 1 refresh + 3 повтора
    expect(fetchMock).toHaveBeenCalledTimes(7)
    const refreshCalls = fetchMock.mock.calls.filter(([url]) =>
      String(url).endsWith('/auth/refresh')
    )
    expect(refreshCalls).toHaveLength(1)

    // повторы ушли с обновлённым токеном
    const retryHeaders = fetchMock.mock.calls
      .slice(3)
      .filter(([url]) => !String(url).endsWith('/auth/refresh'))
      .map(([, init]) => (init?.headers as Record<string, string>).Authorization)
    expect(retryHeaders).toEqual(['Bearer new-token', 'Bearer new-token', 'Bearer new-token'])

    expect(results).toEqual([{ items: [] }, { items: [] }, { items: [] }])
  })

  it('refresh падает → все 3 reject с 401, unauthorizedHandler ровно 1 раз', async () => {
    setAccessToken('old-token')
    const onUnauthorized = vi.fn()
    setOnUnauthorized(onUnauthorized)
    let apiCalls = 0
    fetchMock.mockImplementation(async (url) => {
      if (String(url).endsWith('/auth/refresh')) {
        return error401()
      }
      apiCalls += 1
      return error401()
    })

    const settled = await Promise.allSettled([request('/a'), request('/b'), request('/c')])

    expect(settled.every((r) => r.status === 'rejected')).toBe(true)
    for (const r of settled) {
      expect((r as PromiseRejectedResult).reason).toBeInstanceOf(ApiError)
      expect((r as PromiseRejectedResult).reason.status).toBe(401)
    }
    expect(apiCalls).toBe(3)
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('повторный 401 после refresh → без второго refresh, handler 1 раз', async () => {
    setAccessToken('old-token')
    const onUnauthorized = vi.fn()
    setOnUnauthorized(onUnauthorized)
    let apiCalls = 0
    fetchMock.mockImplementation(async (url) => {
      if (String(url).endsWith('/auth/refresh')) {
        return jsonResponse(200, { accessToken: 'new-token', accessTokenExpiresIn: 900 })
      }
      apiCalls += 1
      return error401() // и первая волна, и повтор
    })

    await expect(request('/data')).rejects.toMatchObject({ status: 401 })

    expect(apiCalls).toBe(2) // исходный + ровно один повтор
    const refreshCalls = fetchMock.mock.calls.filter(([url]) =>
      String(url).endsWith('/auth/refresh')
    )
    expect(refreshCalls).toHaveLength(1)
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('auth:false + 401 → refresh не вызывается, handler не вызывается', async () => {
    const onUnauthorized = vi.fn()
    setOnUnauthorized(onUnauthorized)
    fetchMock.mockImplementation(async () => error401())

    await expect(
      request('/auth/login', { method: 'POST', auth: false, body: { email: 'a@b.c' } })
    ).rejects.toMatchObject({ status: 401 })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('сетевая ошибка: GET повторяется один раз, POST — нет', async () => {
    fetchMock
      .mockRejectedValueOnce(new TypeError('fetch failed'))
      .mockResolvedValueOnce(jsonResponse(200, { ok: true }))
    await expect(request('/flaky')).resolves.toEqual({ ok: true })
    expect(fetchMock).toHaveBeenCalledTimes(2)

    fetchMock.mockReset()
    fetchMock.mockRejectedValue(new TypeError('fetch failed'))
    await expect(request('/write', { method: 'POST', body: {} })).rejects.toMatchObject({
      code: 'NETWORK',
    })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})
