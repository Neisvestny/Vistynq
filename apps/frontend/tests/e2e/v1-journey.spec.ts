import { test, expect } from '@playwright/test'
import type { APIRequestContext, BrowserContext } from '@playwright/test'

/**
 * E2E-сценарий v1 (docs/ROADMAP.md → R16, CI-2): register → verify → canvas →
 * note → link → public-link.
 *
 * Включён в R16: работающие шаги идут подряд (workers=1, fullyParallel=false —
 * делят module-state accessToken/canvasId). Остаются пропущенными:
 *   - «link» — POST /links/direct ещё нет в routes.php (R18/R29);
 *   - «public-link» — PUT /canvases/{id}/access-level ещё нет (R31).
 *
 * Verify: без подтверждённого email login отвечает 403 EMAIL_NOT_VERIFIED
 * (R21/R35) — токен берётся из письма в Mailpit API (:8025).
 *
 * Сессия для UI-шагов: API-логин через `context.request` — он шарит cookie-jar
 * с браузерным контекстом, поэтому refresh-кука с /auth/login подхватывается
 * restoreSession при первом переходе на /dashboard.
 *
 * Рестарт воркера (важно для диагностики CI): Playwright останавливает процесс
 * воркера после первого упавшего теста и перезапускает остаток файла + все
 * ретраи в НОВОМ процессе. Модуль spec'а загружается заново → uniqueEmail
 * новый (ещё не зарегистрирован), accessToken/canvasId снова null. Раньше это
 * давало каскад: одно реальное падение превращалось в 4+ лишних с 401/NULL.
 * Теперь module-state — только кэш: каждый шаг проходит bootstrap-хелперы
 * (ensureRegistered / ensureVerified / loginViaApi / ensureCanvas / ensureNotes),
 * которые досоздают недостающее для текущего воркера.
 */
test.describe('v1 journey: register → verify → canvas → note → link → public link', () => {
  const API_BASE = 'http://localhost:8000/api/v1'
  const MAILPIT_API = 'http://127.0.0.1:8025'

  // + random: два воркера могут загрузить модуль почти одновременно — email
  // обязан различаться, иначе register во втором воркере упадёт с 409.
  const uniqueEmail = `e2e-journey-${Date.now()}-${Math.floor(Math.random() * 100000)}@vistynq.test`
  const password = 'e2e-password-12345'
  const displayName = 'E2E Journey'

  const CANVAS_TITLE = 'E2E Journey Canvas'
  const NOTES = [
    { title: 'Заметка A', x: 40, y: 40 },
    { title: 'Заметка B', x: 360, y: 40 },
  ] as const

  let accessToken: string | null = null
  let canvasId: string | null = null
  let notesCreated = false

  function credentials(): { email: string; password: string } {
    return { email: uniqueEmail, password }
  }

  /* ------------------------------------------------------------------ */
  /* Bootstrap-хелперы: недостающее состояние текущего воркера          */
  /* ------------------------------------------------------------------ */

  /** Регистрирует пользователя воркера, НЕ подтверждая email (нужно тесту verify). */
  async function ensureRegistered(request: APIRequestContext): Promise<void> {
    const status = (await request.post(`${API_BASE}/auth/login`, { data: credentials() })).status()

    if (status === 401) {
      // Рестарт воркера → новый uniqueEmail: пользователя ещё нет.
      const reg = await request.post(`${API_BASE}/auth/register`, {
        data: { ...credentials(), displayName },
      })
      expect([201, 409], `POST /auth/register → ${reg.status()}`).toContain(reg.status())

      return
    }

    expect([200, 403], `POST /auth/login ${uniqueEmail} → ${status}`).toContain(status)
  }

  /** Регистрация (если нужно) + подтверждение email из Mailpit (если нужно). */
  async function ensureVerified(request: APIRequestContext): Promise<void> {
    await ensureRegistered(request)

    const res = await request.post(`${API_BASE}/auth/login`, { data: credentials() })
    if (res.status() === 403) {
      expect((await res.json()).error.code).toBe('EMAIL_NOT_VERIFIED')

      const token = await fetchVerifyToken(request)
      const verified = await request.post(`${API_BASE}/auth/verify-email`, { data: { token } })
      expect(verified.status(), 'POST /auth/verify-email').toBe(200)

      return
    }

    expect(res.status(), `POST /auth/login ${uniqueEmail} → ${res.status()}`).toBe(200)
  }

  /** Access-token для API-шагов; bootstrap при отсутствии (новый воркер). */
  async function ensureToken(request: APIRequestContext): Promise<string> {
    if (accessToken !== null) {
      return accessToken
    }

    await ensureVerified(request)
    const res = await request.post(`${API_BASE}/auth/login`, { data: credentials() })
    expect(res.status(), `POST /auth/login ${uniqueEmail} → ${res.status()}`).toBe(200)

    accessToken = (await res.json()).accessToken as string

    return accessToken
  }

  /** API-логин в контексте браузера: cookie попадает в jar → UI-сессия есть. */
  async function loginViaApi(context: BrowserContext): Promise<string> {
    await ensureVerified(context.request)

    const res = await context.request.post(`${API_BASE}/auth/login`, { data: credentials() })
    expect(res.status(), `POST /auth/login ${uniqueEmail} → ${res.status()}`).toBe(200)
    // Path=/api/v1/auth/refresh → фильтровать cookies по URL нельзя (путь не совпадёт),
    // берём все куки контекста и проверяем по имени.
    expect(
      (await context.cookies()).some((c) => c.name === 'vistynq_refresh'),
      'login должен выдать refresh-куку — без неё restoreSession в UI не восстановит сессию'
    ).toBe(true)

    accessToken = (await res.json()).accessToken as string

    return accessToken
  }

  /** Канвас воркера: создаётся через API, если UI-шаг его не создал. */
  async function ensureCanvas(request: APIRequestContext): Promise<string> {
    if (canvasId !== null) {
      return canvasId
    }

    const token = await ensureToken(request)
    const res = await request.post(`${API_BASE}/canvases`, {
      headers: { Authorization: `Bearer ${token}` },
      data: { title: CANVAS_TITLE },
    })
    expect(res.status(), `POST /canvases → ${res.status()}`).toBe(201)

    canvasId = ((await res.json()).id as string) ?? null
    expect(canvasId, 'POST /canvases вернул канвас без id').toBeTruthy()

    return canvasId as string
  }

  /** Обе заметки канваса: создание идемпотентно в рамках воркера (флаг notesCreated). */
  async function ensureNotes(request: APIRequestContext): Promise<void> {
    if (notesCreated) {
      return
    }

    const canvas = await ensureCanvas(request)
    const token = await ensureToken(request)
    const headers = { Authorization: `Bearer ${token}` }

    for (const note of NOTES) {
      const res = await request.post(`${API_BASE}/canvases/${canvas}/notes`, {
        headers,
        data: note,
      })
      expect(res.status(), `POST /canvases/…/notes (${note.title}) → ${res.status()}`).toBe(201)
    }

    notesCreated = true
  }

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

  /* ------------------------------------------------------------------ */
  /* Шаги сценария                                                       */
  /* ------------------------------------------------------------------ */

  test('register new user', async ({ request }) => {
    const res = await request.post(`${API_BASE}/auth/register`, {
      data: { ...credentials(), displayName },
    })
    expect(res.status()).toBe(201)
  })

  test('verify email via Mailpit before login', async ({ request }) => {
    // Ретрай/новый воркер мог перезагрузить модуль → сначала гарантируем
    // регистрацию текущего uniqueEmail, но БЕЗ подтверждения: дальше проверяем
    // именно «login до подтверждения обязан падать с 403 EMAIL_NOT_VERIFIED» (R21).
    await ensureRegistered(request)

    const before = await request.post(`${API_BASE}/auth/login`, { data: credentials() })
    expect(before.status()).toBe(403)
    expect((await before.json()).error.code).toBe('EMAIL_NOT_VERIFIED')

    const token = await fetchVerifyToken(request)
    const res = await request.post(`${API_BASE}/auth/verify-email`, {
      data: { token },
    })
    expect(res.status()).toBe(200)
  })

  test('login through the UI', async ({ page, request }) => {
    // Bootstrap через отдельный `request`-fixture: его cookie-jar НЕ связан с
    // браузерным контекстом, поэтому UI-логин остаётся настоящим входом с формы.
    await ensureVerified(request)

    await page.goto('/login')
    await page.getByLabel('Email').fill(uniqueEmail)
    await page.getByLabel('Пароль').fill(password)
    await page.getByRole('button', { name: 'Войти' }).click()

    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByRole('heading', { name: /Привет/ })).toBeVisible()
  })

  test('create a canvas through the UI', async ({ page, context }) => {
    // фиксируем access-token для API-шагов ниже; cookie-jar браузера получает сессию
    await loginViaApi(context)

    await page.goto('/dashboard')
    await page.getByRole('button', { name: /Создать канвас/ }).click()
    await page.getByLabel('Название').fill(CANVAS_TITLE)
    await page.getByRole('button', { name: 'Создать', exact: true }).click()

    await expect(page).toHaveURL(/\/canvas\/[0-9a-f-]+$/)
    canvasId = new URL(page.url()).pathname.split('/').pop() ?? null
    expect(canvasId).toBeTruthy()

    // пустой канвас — read-only empty-state (R14)
    await expect(page.getByText('Здесь пока нет заметок')).toBeVisible()
  })

  test('canvas appears in the dashboard list with note count', async ({ page, context }) => {
    await loginViaApi(context)
    // Канвас мог остаться в предыдущем (упавшем) воркере — создаём для текущего.
    await ensureCanvas(context.request)

    await page.goto('/dashboard')
    await expect(page.getByRole('heading', { name: CANVAS_TITLE })).toBeVisible()
    // .dashboard__card, а не xpath-ancestor: внутри есть .dashboard__card-top,
    // и contains(@class, ...) матчит его как подстроку.
    const card = page.locator('.dashboard__card').filter({ hasText: CANVAS_TITLE })
    await expect(card).toHaveCount(1)
    await expect(card).toContainText('0 зам.')
  })

  test('create notes (API)', async ({ request }) => {
    // 201 на каждую заметку проверяется внутри ensureNotes.
    await ensureNotes(request)
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
    await loginViaApi(context)
    await ensureCanvas(context.request)
    await ensureNotes(context.request)

    await page.goto(`/canvas/${canvasId}`)
    await expect(page.getByText('Заметка A')).toBeVisible()
    await expect(page.getByText('Заметка B')).toBeVisible()
    // рёбра появятся в DOM после R18/R25 (сейчас read-only борд рисует только ноды)
    // await expect(page.locator('.vue-flow__edge')).toHaveCount(1)
  })

  // R31: PUT /canvases/{id}/access-level {level:'public'} ещё нет
  // в routes.php, slug ниже — заглушка; включим после публичного доступа.
  test.skip('public link opens shared view without login', async ({ browser }) => {
    expect(accessToken).toBeTruthy()

    // Для R31:
    // const res = await request.put(`${API_BASE}/canvases/${canvasId}/access-level`, {
    //   headers: { Authorization: `Bearer ${accessToken}` },
    //   data: { level: 'public' },
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
