import { test, expect } from '@playwright/test'
import type { BrowserContext } from '@playwright/test'

/**
 * E2E-сценарий v1 (docs/ROADMAP.md → R16, CI-2): register → canvas → note →
 * link → public-link.
 *
 * Включён в R16: работающие шаги идут подряд (workers=1, fullyParallel=false —
 * делят module-state accessToken/canvasId). Остаются пропущенными:
 *   - «link» — POST /links/direct ещё нет в routes.php (R18/R29);
 *   - «public-link» — PUT /canvases/{id}/access-level ещё нет (R31).
 *
 * Сессия для UI-шагов: API-логин через `context.request` — он шарит cookie-jar
 * с браузерным контекстом, поэтому refresh-кука с /auth/login подхватывается
 * restoreSession при первом переходе на /dashboard.
 */
test.describe('v1 journey: register → canvas → note → link → public link', () => {
  const API_BASE = 'http://localhost:8000/api/v1'

  const uniqueEmail = `e2e-journey-${Date.now()}@vistynq.test`
  const password = 'e2e-password-12345'
  const displayName = 'E2E Journey'

  let accessToken: string | null = null
  let canvasId: string | null = null

  /** API-логин в контексте браузера: cookie попадает в jar → UI-сессия есть. */
  async function loginViaApi(context: BrowserContext): Promise<string> {
    const res = await context.request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    expect(res.status()).toBe(200)
    // Path=/api/v1/auth/refresh → фильтровать cookies по URL нельзя (путь не совпадёт),
    // берём все куки контекста и проверяем по имени.
    expect(
      (await context.cookies()).some((c) => c.name === 'vistynq_refresh'),
      'login должен выдать refresh-куку — без неё restoreSession в UI не восстановит сессию'
    ).toBe(true)

    return (await res.json()).accessToken as string
  }

  test('register new user', async ({ request }) => {
    const res = await request.post(`${API_BASE}/auth/register`, {
      data: { email: uniqueEmail, password, displayName },
    })
    expect(res.status()).toBe(201)
  })

  test('login through the UI', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email').fill(uniqueEmail)
    await page.getByLabel('Пароль').fill(password)
    await page.getByRole('button', { name: 'Войти' }).click()

    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByRole('heading', { name: /Привет/ })).toBeVisible()
  })

  test('create a canvas through the UI', async ({ page, context }) => {
    // фиксируем access-token для API-шагов ниже; cookie-jar браузера получает сессию
    accessToken = await loginViaApi(context)

    await page.goto('/dashboard')
    await page.getByRole('button', { name: /Создать канвас/ }).click()
    await page.getByLabel('Название').fill('E2E Journey Canvas')
    await page.getByRole('button', { name: 'Создать', exact: true }).click()

    await expect(page).toHaveURL(/\/canvas\/[0-9a-f-]+$/)
    canvasId = new URL(page.url()).pathname.split('/').pop() ?? null
    expect(canvasId).toBeTruthy()

    // пустой канвас — read-only empty-state (R14)
    await expect(page.getByText('Здесь пока нет заметок')).toBeVisible()
  })

  test('canvas appears in the dashboard list with note count', async ({ page, context }) => {
    expect(canvasId).toBeTruthy()
    await loginViaApi(context)

    await page.goto('/dashboard')
    await expect(page.getByRole('heading', { name: 'E2E Journey Canvas' })).toBeVisible()
    // .dashboard__card, а не xpath-ancestor: внутри есть .dashboard__card-top,
    // и contains(@class, ...) матчит его как подстроку.
    const card = page.locator('.dashboard__card').filter({ hasText: 'E2E Journey Canvas' })
    await expect(card).toHaveCount(1)
    await expect(card).toContainText('0 зам.')
  })

  test('create notes (API)', async ({ request }) => {
    expect(accessToken).toBeTruthy()
    expect(canvasId).toBeTruthy()
    const headers = { Authorization: `Bearer ${accessToken}` }

    const noteA = await request.post(`${API_BASE}/canvases/${canvasId}/notes`, {
      headers,
      data: { title: 'Заметка A', x: 40, y: 40 },
    })
    expect(noteA.status()).toBe(201)

    const noteB = await request.post(`${API_BASE}/canvases/${canvasId}/notes`, {
      headers,
      data: { title: 'Заметка B', x: 360, y: 40 },
    })
    expect(noteB.status()).toBe(201)
  })

  // R18/R29: связь создаётся через wikilink-разметку в теле заметки —
  // парсинг/POST /links/direct ещё не реализованы, шаг включим после них.
  test.skip('create a link between notes (wikilink)', async () => {
    expect(accessToken).toBeTruthy()
    expect(canvasId).toBeTruthy()

    // Для R18/R29 (включить вместо этого комментария):
    //   1) POST /canvases/{id}/notes/{noteId}/body с wikilink `[[Заметка B]]`,
    //   2) или POST /links/direct { fromNoteId, toNoteId } — когда появится в routes.php.
    // Затем проверить, что ребро появилось в GET /canvases/{id}/graph.
  })

  test('graph renders nodes (R25 replaces read-only board)', async ({ page, context }) => {
    expect(canvasId).toBeTruthy()
    await loginViaApi(context)

    await page.goto(`/canvas/${canvasId}`)
    await expect(page.getByText('Заметка A')).toBeVisible()
    await expect(page.getByText('Заметка B')).toBeVisible()
    // рёбра появятся в DOM после R18/R25 (сейчас read-only борд рисует только ноды)
    // await expect(page.locator('.vue-flow__edge')).toHaveCount(1)
  })

  // R31: PUT /canvases/{id}/access-level {level:'public_link'} ещё нет
  // в routes.php, slug ниже — заглушка; включим после публичного доступа.
  test.skip('public link opens shared view without login', async ({ browser }) => {
    expect(accessToken).toBeTruthy()

    // Для R31:
    // const res = await request.put(`${API_BASE}/canvases/${canvasId}/access-level`, {
    //   headers: { Authorization: `Bearer ${accessToken}` },
    //   data: { level: 'public_link' },
    // })
    // const slug = (await res.json()).publicSlug
    const slug = 'todo-public-slug'

    // чистый контекст без кук/токенов — анонимный доступ
    const anonContext = await browser.newContext()
    const anonPage = await anonContext.newPage()
    await anonPage.goto(`http://localhost:5173/shared/canvas/${slug}`)
    await expect(anonPage.locator('.shared-banner')).toBeVisible()
    await anonContext.close()
  })
})
