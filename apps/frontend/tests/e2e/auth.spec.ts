import { test, expect } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'

test.describe('auth', () => {
  const API_BASE = 'http://localhost:8000/api/v1'
  const MAILPIT_API = 'http://127.0.0.1:8025'

  const uniqueEmail = `e2e-${Date.now()}-${Math.floor(Math.random() * 10000)}@vistynq.test`
  const password = 'e2e-password-12345'
  const displayName = 'E2E Test User'

  let accessToken: string | null = null

  test('register new user', async ({ request }) => {
    const res = await request.post(`${API_BASE}/auth/register`, {
      data: { email: uniqueEmail, password, displayName },
    })
    expect(res.status()).toBe(201)
  })

  test('verify email via Mailpit before login', async ({ request }) => {
    // Login до подтверждения обязан падать с 403 EMAIL_NOT_VERIFIED (R21).
    const before = await request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    expect(before.status()).toBe(403)
    expect((await before.json()).error.code).toBe('EMAIL_NOT_VERIFIED')

    const token = await fetchVerifyToken(request)
    const res = await request.post(`${API_BASE}/auth/verify-email`, {
      data: { token },
    })
    expect(res.status()).toBe(200)
  })

  /** Токен подтверждения — из письма в Mailpit (SMTP-приёмник dev/CI, :8025). */
  async function fetchVerifyToken(request: APIRequestContext): Promise<string> {
    const search = await request.get(
      `${MAILPIT_API}/api/v1/search?query=${encodeURIComponent(`to:${uniqueEmail}`)}&limit=10`
    )
    expect(search.ok(), 'Mailpit API должен отвечать на :8025').toBe(true)
    const messages = ((await search.json()).messages ?? []) as Array<{ ID: string }>
    expect(messages.length, 'письмо с подтверждением должно быть в Mailpit').toBeGreaterThan(0)

    const message = await request.get(`${MAILPIT_API}/api/v1/message/${messages[0].ID}`)
    expect(message.ok()).toBe(true)
    const text = (((await message.json()).Text ?? '') as string) ?? ''

    const match = text.match(/verify-email\?token=([0-9a-f]{64})/)
    expect(match, 'в тексте письма нет ссылки /verify-email?token=…').not.toBeNull()

    return match![1]
  }

  test('login with credentials', async ({ request }) => {
    const res = await request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.accessToken).toBeTruthy()
    expect(typeof body.accessToken).toBe('string')
    accessToken = body.accessToken
  })

  test('get current user with bearer token', async ({ request }) => {
    expect(accessToken).not.toBeNull()

    const res = await request.get(`${API_BASE}/auth/me`, {
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.id).toBeTruthy()
    expect(body.email).toBe(uniqueEmail)
  })

  test('logout', async ({ request }) => {
    expect(accessToken).not.toBeNull()

    const res = await request.post(`${API_BASE}/auth/logout`, {
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
    })
    expect([200, 204]).toContain(res.status())
  })
})
