import { test, expect } from '@playwright/test'

test.describe('smoke', () => {
  const API_BASE = 'http://localhost:8000/api/v1'

  test('healthz returns 200', async ({ request }) => {
    const res = await request.get('http://localhost:8000/healthz')
    expect(res.status()).toBe(200)
  })

  test('api healthz returns 200', async ({ request }) => {
    const res = await request.get(`${API_BASE}/healthz`)
    expect(res.status()).toBe(200)
  })

  test('frontend loads', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveTitle(/Vistynq/i)
  })
})
