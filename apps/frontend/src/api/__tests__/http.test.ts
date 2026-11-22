/**
 * Testy http-klienta (roadmap R04): Bearer, single-flight refresh pri
 * parallelnykh 401, povtor zaprosa, povtornyy 401 = logout-callback,
 * auth:false bez refresh, setevoy retry GET.
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
  return jsonResponse(401, { error: { code: 'UNAUTHORIZED', message: 'Trebuetsya vkhod' } })
}

const fetchMock = vi.fn<typeof fetch>()

function callHeaders(index: number): Record<string, string> {
  const init = fetchMock.mock.calls[index]?.[1]
  return (init?.headers ?? {}) as Record<string, string>
}

describe('http-klient (R04)', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    // setAccessToken(non-null) sbrasyvaet sessionExpiredNotified, zatem chistim token.
    setAccessToken('seed')
    setAccessToken(null)
    setOnUnauthorized(vi.fn())
    setOnTokenRefreshed(vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('GET podstavlyaet Authorization: Bearer; auth:false — bez zagolovka', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, { ok: true }))
    setAccessToken('t-1')

    await request('/me')
    await request('/auth/login', { method: 'POST', auth: false, body: { email: 'a@b.c' } })

    expect(callHeaders(0).Authorization).toBe('Bearer t-1')
    expect(callHeaders(1).Authorization).toBeUndefined()
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('kriteriy: 3 parallelnykh 401 → odin refresh, povtory s novym tokenom', async () => {
    setAccessToken('old-token')
    let apiCalls = 0
    fetchMock.mockImplementation(async (url) => {
      if (String(url).endsWith('/auth/refresh')) {
        return jsonResponse(200, { accessToken: 'new-token', accessTokenExpiresIn: 900 })
      }
      apiCalls += 1
      // pervaya volna — vse tri 401, povtory — 200
      return apiCalls <= 3 ? error401() : jsonResponse(200, { items: [] })
    })

    const results = await Promise.all([
      request<{ items: unknown[] }>('/canvases'),
      request<{ items: unknown[] }>('/canvases/members'),
      request<{ items: unknown[] }>('/canvases/invites'),
    ])

    // 3 zaprosa + 1 refresh + 3 povtora
    expect(fetchMock).toHaveBeenCalledTimes(7)
    const refreshCalls = fetchMock.mock.calls.filter(([url]) =>
      String(url).endsWith('/auth/refresh')
    )
    expect(refreshCalls).toHaveLength(1)

    // povtory ushli s obnovlyonnym tokenom
    const retryHeaders = fetchMock.mock.calls
      .slice(3)
      .filter(([url]) => !String(url).endsWith('/auth/refresh'))
      .map(([, init]) => (init?.headers as Record<string, string>).Authorization)
    expect(retryHeaders).toEqual(['Bearer new-token', 'Bearer new-token', 'Bearer new-token'])

    expect(results).toEqual([{ items: [] }, { items: [] }, { items: [] }])
  })

  it('refresh padaet → vse 3 reject s 401, unauthorizedHandler rovno 1 raz', async () => {
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

  it('povtornyy 401 posle refresh → bez vtorogo refresh, handler 1 raz', async () => {
    setAccessToken('old-token')
    const onUnauthorized = vi.fn()
    setOnUnauthorized(onUnauthorized)
    let apiCalls = 0
    fetchMock.mockImplementation(async (url) => {
      if (String(url).endsWith('/auth/refresh')) {
        return jsonResponse(200, { accessToken: 'new-token', accessTokenExpiresIn: 900 })
      }
      apiCalls += 1
      return error401() // i pervaya volna, i povtor
    })

    await expect(request('/data')).rejects.toMatchObject({ status: 401 })

    expect(apiCalls).toBe(2) // iskhodnyy + rovno odin povtor
    const refreshCalls = fetchMock.mock.calls.filter(([url]) =>
      String(url).endsWith('/auth/refresh')
    )
    expect(refreshCalls).toHaveLength(1)
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('auth:false + 401 → refresh ne vyzyvaetsya, handler ne vyzyvaetsya', async () => {
    const onUnauthorized = vi.fn()
    setOnUnauthorized(onUnauthorized)
    fetchMock.mockImplementation(async () => error401())

    await expect(
      request('/auth/login', { method: 'POST', auth: false, body: { email: 'a@b.c' } })
    ).rejects.toMatchObject({ status: 401 })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('setevaya oshibka: GET povtoryaetsya odin raz, POST — net', async () => {
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
