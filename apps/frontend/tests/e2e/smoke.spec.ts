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

  /**
   * Прокси dev-сервера (vite `server.proxy['/api']` → VITE_API_TARGET).
   * Без него ВСЕ UI-шаги (login и дальше) падают с невнятными ошибками —
   * ловим первым тестом и с понятным сообщением.
   */
  test('frontend proxies /api to backend', async ({ request }) => {
    const res = await request.get('http://localhost:5173/api/v1/healthz')
    expect(
      res.status(),
      'dev-сервер не проксирует /api на backend — проверь server.proxy и VITE_API_TARGET'
    ).toBe(200)
  })

  test('frontend loads', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveTitle(/Vistynq/i)
  })
})
