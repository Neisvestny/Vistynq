import { test, expect } from '@playwright/test'

test.describe('auth', () => {
  const API_BASE = 'http://localhost:8000/api/v1'

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
