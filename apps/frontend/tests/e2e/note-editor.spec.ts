import { test, expect } from '@playwright/test'
import type { APIRequestContext, BrowserContext, Locator, Page } from '@playwright/test'

/**
 * R28 — панель редактора заметки: открытие двойным кликом по ноде,
 * манифест + markdown-тело, сохранение на API,Side-переключатель,
 * заглушка «Доступ» (R32) и guard на закрытие с несохранённым черновиком.
 *
 * Сессия браузера — API-логин через context.request (общий cookie-jar с
 * страницей), данные создаются через API, чтобы тест не зависел от UI-шагов
 * создания канваса/заметки. fullyParallel=false в playwright.config.ts —
 * состояние воркера безопасно.
 */
test.describe('note editor (R28)', () => {
  const API_BASE = 'http://localhost:8000/api/v1'
  const MAILPIT_API = 'http://127.0.0.1:8025'

  const uniqueEmail = `e2e-editor-${Date.now()}-${Math.floor(Math.random() * 100000)}@vistynq.test`
  const password = 'e2e-password-12345'
  const NOTE_TITLE = 'Заметка для редактора'

  let accessToken: string | null = null
  let canvasId: string | null = null
  let noteId: string | null = null

  function authHeaders(): { Authorization: string } {
    expect(accessToken, 'access-token должен быть получен при логине').toBeTruthy()
    return { Authorization: `Bearer ${accessToken as string}` }
  }

  async function fetchVerifyToken(request: APIRequestContext): Promise<string> {
    const search = await request.get(
      `${MAILPIT_API}/api/v1/search?query=${encodeURIComponent(`to:${uniqueEmail}`)}&limit=10`
    )
    expect(search.ok(), 'Mailpit API должен отвечать на :8025').toBe(true)
    const messages = ((await search.json()).messages ?? []) as Array<{ ID: string }>
    expect(messages.length, 'письмо с подтверждением должно быть в Mailpit').toBeGreaterThan(0)

    const message = await request.get(`${MAILPIT_API}/api/v1/message/${messages[0].ID}`)
    expect(message.ok()).toBe(true)
    const text = ((await message.json()).Text ?? '') as string
    const match = text.match(/verify-email\?token=([0-9a-f]{64})/)
    expect(match, 'в письме нет ссылки /verify-email?token=…').not.toBeNull()

    return match![1]
  }

  async function ensureVerified(request: APIRequestContext): Promise<void> {
    const reg = await request.post(`${API_BASE}/auth/register`, {
      data: { email: uniqueEmail, password, displayName: 'E2E Editor' },
    })
    expect([201, 409], `POST /auth/register → ${reg.status()}`).toContain(reg.status())

    const login = await request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    if (login.status() === 403) {
      expect((await login.json()).error.code).toBe('EMAIL_NOT_VERIFIED')
      const verified = await request.post(`${API_BASE}/auth/verify-email`, {
        data: { token: await fetchVerifyToken(request) },
      })
      expect(verified.status()).toBe(200)
      return
    }
    expect(login.status(), `POST /auth/login → ${login.status()}`).toBe(200)
  }

  async function loginViaApi(context: BrowserContext): Promise<void> {
    await ensureVerified(context.request)
    const res = await context.request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    expect(res.status()).toBe(200)
    accessToken = ((await res.json()).accessToken as string) ?? null
    expect(
      (await context.cookies()).some((c) => c.name === 'vistynq_refresh'),
      'логин должен выдать refresh-куку для restoreSession'
    ).toBe(true)
  }

  async function ensureNote(
    request: APIRequestContext
  ): Promise<{ canvasId: string; noteId: string }> {
    if (canvasId !== null && noteId !== null) return { canvasId, noteId }

    const headers = authHeaders()
    const canvas = await request.post(`${API_BASE}/canvases`, {
      headers,
      data: { title: 'E2E Editor Canvas' },
    })
    expect(canvas.status(), `POST /canvases → ${canvas.status()}`).toBe(201)
    canvasId = ((await canvas.json()).id as string) ?? null
    expect(canvasId).toBeTruthy()

    const note = await request.post(`${API_BASE}/canvases/${canvasId as string}/notes`, {
      headers,
      data: { title: NOTE_TITLE, x: 100, y: 100 },
    })
    expect(note.status(), `POST /notes → ${note.status()}`).toBe(201)
    noteId = ((await note.json()).id as string) ?? null
    expect(noteId).toBeTruthy()

    return { canvasId: canvasId as string, noteId: noteId as string }
  }

  async function openEditor(page: Page): Promise<Locator> {
    await page.locator('.note-node').first().dblclick()
    const sheet = page.locator('.ui-sheet__content')
    await expect(sheet).toBeVisible()
    return sheet
  }

  test('открывает редактор, сохраняет заголовок и markdown-тело', async ({
    page,
    context,
    request,
  }) => {
    await loginViaApi(context)
    const ids = await ensureNote(request)

    await page.goto(`/canvas/${ids.canvasId}`)
    await expect(page.getByText(NOTE_TITLE)).toBeVisible()

    const sheet = await openEditor(page)
    const titleInput = sheet.getByPlaceholder('Название заметки')
    await expect(titleInput).toHaveValue(NOTE_TITLE)
    await expect(sheet.getByText('Содержимое')).toBeVisible()

    await titleInput.fill('Заметка после редактирования')
    await sheet.locator('.note-editor__prose').click()
    await page.keyboard.type('## Новый раздел\n\nТекст из редактора R28')

    await expect(sheet.getByText('Есть несохранённые изменения')).toBeVisible()
    await sheet.getByRole('button', { name: 'Сохранить' }).click()

    await expect(page.getByText('Заметка сохранена')).toBeVisible()
    await expect(titleInput).toHaveValue('Заметка после редактирования')

    const detail = await request.get(`${API_BASE}/notes/${ids.noteId}`, { headers: authHeaders() })
    expect(detail.status()).toBe(200)
    const body = await detail.json()
    expect(body.title).toBe('Заметка после редактирования')
    expect(body.bodyMd).toContain('Текст из редактора R28')
  })

  test('переключатель стороны панели сохраняется между перезагрузками', async ({
    page,
    context,
    request,
  }) => {
    await loginViaApi(context)
    const ids = await ensureNote(request)

    await page.goto(`/canvas/${ids.canvasId}`)
    await expect(page.locator('.note-node').first()).toBeVisible()

    let sheet = await openEditor(page)
    await expect(sheet).toHaveClass(/ui-sheet__content--right/)

    await sheet.getByRole('button', { name: 'Переместить панель слева' }).click()
    await expect(page.locator('.ui-sheet__content')).toHaveClass(/ui-sheet__content--left/)
    expect(await page.evaluate(() => localStorage.getItem('vistynq:note-editor-side'))).toBe('left')

    await page.reload()
    await expect(page.locator('.note-node').first()).toBeVisible()
    sheet = await openEditor(page)
    await expect(sheet).toHaveClass(/ui-sheet__content--left/)

    // Вернуть значение по умолчанию, чтобы не влиять на соседние тесты.
    await sheet.getByRole('button', { name: 'Переместить панель справа' }).click()
    await expect(page.locator('.ui-sheet__content')).toHaveClass(/ui-sheet__content--right/)
  })

  test('кнопка «Доступ» показывает заглушку R32', async ({ page, context, request }) => {
    await loginViaApi(context)
    const ids = await ensureNote(request)

    await page.goto(`/canvas/${ids.canvasId}`)
    const sheet = await openEditor(page)

    await sheet.getByRole('button', { name: 'Доступ…' }).click()
    await expect(page.getByText('Диалог управления доступом появится в R32')).toBeVisible()
  })

  test('закрытие с несохранёнными изменениями спрашивает подтверждение', async ({
    page,
    context,
    request,
  }) => {
    await loginViaApi(context)
    const ids = await ensureNote(request)

    await page.goto(`/canvas/${ids.canvasId}`)
    const sheet = await openEditor(page)

    await sheet.getByPlaceholder('Название заметки').fill('Черновик без сохранения')
    await expect(sheet.getByText('Есть несохранённые изменения')).toBeVisible()

    // По умолчанию Playwright закрывает диалоги — явно подтверждаем закрытие.
    page.once('dialog', (dialog) => void dialog.accept())
    await sheet.getByRole('button', { name: 'Закрыть', exact: true }).click()
    await expect(page.locator('.ui-sheet__content')).toBeHidden()
  })
})
