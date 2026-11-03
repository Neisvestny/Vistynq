import { test, expect } from '@playwright/test'

/**
 * Черновик e2e-сценария v1 для R16 (docs/ROADMAP.md → R16):
 * register → canvas → note → link → public-link.
 *
 * Сейчас пропущен целиком: шаги «ссылка» и «публичная ссылка» ждут
 * эндпоинты, которых ещё нет в routes.php (/links/direct, /access-level),
 * а финальная проверка нод/рёбер — UI-редактора (R25–R27).
 *
 * Включение в R16: убрать test.skip в beforeEach и дописать недостающие
 * вызовы API. Формат шагов (API-шаги быстрые, UI — только там, где проверяем
 * пользовательский сценарий) согласован с tests/e2e/auth.spec.ts.
 */
test.describe('v1 journey: register → canvas → note → link → public link', () => {
  const API_BASE = 'http://localhost:8000/api/v1'

  const uniqueEmail = `e2e-journey-${Date.now()}@vistynq.test`
  const password = 'e2e-password-12345'
  const displayName = 'E2E Journey'

  let accessToken: string | null = null
  let canvasId: string | null = null

  test.beforeEach(() => {
    // Для R16: удалить строку — заработает после /links/direct + /access-level + UI-редактора
    test.skip(
      true,
      'Черновик R16: включить после реализации ссылок, доступов и редактора (R25–R31)'
    )
  })

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

  test('create a canvas through the UI', async ({ page, request }) => {
    // фиксируем access-token для API-шагов ниже (без UI-логина)
    const res = await request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    expect(res.status()).toBe(200)
    accessToken = (await res.json()).accessToken

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

  test('canvas appears in the dashboard list with note count', async ({ page }) => {
    expect(canvasId).toBeTruthy()

    await page.goto('/dashboard')
    const card = page.getByRole('heading', { name: 'E2E Journey Canvas' })
    await expect(card).toBeVisible()
    await expect(
      card.locator('xpath=ancestor::*[contains(@class,"dashboard__card")]')
    ).toContainText('0 зам.')
  })

  test('create notes and a link (API)', async ({ request }) => {
    // Для R16: POST /links/direct ещё не зарегистрирован в routes.php (R18/R29)
    expect(accessToken).toBeTruthy()
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

    // Для R16: link = await request.post(`${API_BASE}/links/direct`, {
    //   headers, data: { fromNoteId: (await noteA.json()).id, toNoteId: (await noteB.json()).id },
    // })
  })

  test('graph renders nodes and edges (R25 replaces read-only board)', async ({ page }) => {
    await page.goto(`/canvas/${canvasId}`)
    await expect(page.getByText('Заметка A')).toBeVisible()
    await expect(page.getByText('Заметка B')).toBeVisible()
    // рёбра появятся в DOM после R25 (сейчас read-only борд рисует только ноды)
    // await expect(page.locator('.vue-flow__edge')).toHaveCount(1)
  })

  test('public link opens shared view without login', async ({ context }) => {
    expect(accessToken).toBeTruthy()

    // Для R16: PUT /canvases/{id}/access-level {level:'public_link'} ещё не в routes.php (R31)
    // const res = await request.put(`${API_BASE}/canvases/${canvasId}/access-level`, {
    //   headers: { Authorization: `Bearer ${accessToken}` },
    //   data: { level: 'public_link' },
    // })
    // const slug = (await res.json()).publicSlug
    const slug = 'todo-public-slug'

    // чистый контекст без кук/токенов — анонимный доступ
    const anonPage = await context.browser()!.newPage()
    await anonPage.goto(`http://localhost:5173/shared/canvas/${slug}`)
    await expect(anonPage.locator('.shared-banner')).toBeVisible()
    await anonPage.close()
  })
})
