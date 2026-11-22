import { test, expect } from '@playwright/test'
import type { APIRequestContext, BrowserContext, Locator, Page } from '@playwright/test'

/**
 * R29 — архив-панель: секции, пагинация и операции жизненного цикла.
 *
 * Данные создаются через API (как в note-editor.spec.ts), чтобы тест не
 * зависел от UI-шагов создания канваса/заметок. fullyParallel=false в
 * playwright.config.ts — состояние воркера безопасно.
 */
test.describe('archive panel (R29)', () => {
  const API_BASE = 'http://localhost:8000/api/v1'
  const MAILPIT_API = 'http://127.0.0.1:8025'

  const uniqueEmail = `e2e-archive-${Date.now()}-${Math.floor(Math.random() * 100000)}@vistynq.test`
  const password = 'e2e-password-12345'

  let accessToken: string | null = null

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
      data: { email: uniqueEmail, password, displayName: 'E2E Archive' },
    })
    expect([201, 409], `POST /auth/register → ${reg.status()}`).toContain(reg.status())

    const login = await request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    if (login.status() === 403) {
      const verified = await request.post(`${API_BASE}/auth/verify-email`, {
        data: { token: await fetchVerifyToken(request) },
      })
      expect(verified.status()).toBe(200)
    } else {
      expect(login.status(), `POST /auth/login → ${login.status()}`).toBe(200)
    }
  }

  async function loginViaApi(context: BrowserContext): Promise<void> {
    await ensureVerified(context.request)
    const res = await context.request.post(`${API_BASE}/auth/login`, {
      data: { email: uniqueEmail, password },
    })
    expect(res.status()).toBe(200)
    accessToken = ((await res.json()).accessToken as string) ?? null
    expect(accessToken).toBeTruthy()
  }

  async function createCanvas(request: APIRequestContext, title: string): Promise<string> {
    const res = await request.post(`${API_BASE}/canvases`, {
      headers: authHeaders(),
      data: { title },
    })
    expect(res.status(), `POST /canvases → ${res.status()}`).toBe(201)
    const id = ((await res.json()).id as string) ?? ''
    expect(id).toBeTruthy()
    return id
  }

  async function createNote(
    request: APIRequestContext,
    canvasId: string,
    title: string,
    x = 100
  ): Promise<string> {
    const res = await request.post(`${API_BASE}/canvases/${canvasId}/notes`, {
      headers: authHeaders(),
      data: { title, x, y: 100 },
    })
    expect(res.status(), `POST /notes → ${res.status()}`).toBe(201)
    const id = ((await res.json()).id as string) ?? ''
    expect(id).toBeTruthy()
    return id
  }

  async function openArchive(page: Page): Promise<Locator> {
    await page.getByRole('button', { name: 'Архив' }).click()
    const sheet = page.locator('.ui-sheet__content')
    await expect(sheet).toBeVisible()
    await expect(sheet.getByRole('heading', { name: 'Архив заметок' })).toBeVisible()
    return sheet
  }

  function item(sheet: Locator, title: string): Locator {
    return sheet.locator('.archive-panel__item').filter({ hasText: title })
  }

  test('показывает обе секции и постраничную навигацию', async ({ page, context, request }) => {
    await loginViaApi(context)
    const canvasId = await createCanvas(request, 'E2E Archive Pagination')

    for (let i = 0; i < 21; i += 1) {
      const id = await createNote(request, canvasId, `Неактуальная ${i}`)
      const patch = await request.patch(`${API_BASE}/notes/${id}`, {
        headers: authHeaders(),
        data: { isRelevant: false },
      })
      expect(patch.status()).toBe(200)
    }

    await page.goto(`/canvas/${canvasId}`)
    const sheet = await openArchive(page)

    await expect(sheet.getByText('Страница 1 из 2 · всего 21')).toBeVisible()
    await expect(sheet.getByText('Неактуальные')).toBeVisible()
    await expect(sheet.getByText('Удалённые')).toBeVisible()

    await sheet.getByRole('button', { name: 'Вперёд' }).click()
    await expect(sheet.getByText('Страница 2 из 2 · всего 21')).toBeVisible()

    await sheet.getByRole('tab', { name: 'Удалённые' }).click()
    await expect(sheet.getByText('Корзина пуста')).toBeVisible()
    await expect(sheet.getByText('Страница 1 из 2 · всего 21')).toBeHidden()
  })

  test('«Вернуть» поднимает заметку обратно на канвас', async ({ page, context, request }) => {
    await loginViaApi(context)
    const canvasId = await createCanvas(request, 'E2E Archive Restore Relevant')
    const title = 'Возвращаемая заметка'
    const noteId = await createNote(request, canvasId, title)
    const patch = await request.patch(`${API_BASE}/notes/${noteId}`, {
      headers: authHeaders(),
      data: { isRelevant: false },
    })
    expect(patch.status()).toBe(200)

    await page.goto(`/canvas/${canvasId}`)
    // Неактуальные заметки по умолчанию скрыты — до «Вернуть» ноды нет.
    await expect(page.locator('.note-node')).toHaveCount(0)
    const sheet = await openArchive(page)

    await item(sheet, title).getByRole('button', { name: 'Вернуть' }).click()
    await expect(page.getByText('Заметка возвращена на канвас', { exact: true })).toBeVisible()
    await expect(item(sheet, title)).toHaveCount(0)

    const detail = await request.get(`${API_BASE}/notes/${noteId}`, { headers: authHeaders() })
    expect((await detail.json()).isRelevant as boolean).toBe(true)
    await expect(page.locator('.note-node').filter({ hasText: title })).toBeVisible()
  })

  test('«Восстановить» возвращает удалённую заметку в прежней позиции', async ({
    page,
    context,
    request,
  }) => {
    await loginViaApi(context)
    const canvasId = await createCanvas(request, 'E2E Archive Restore Deleted')
    const title = 'Восстанавливаемая заметка'
    const noteId = await createNote(request, canvasId, title, 260)
    const removed = await request.delete(`${API_BASE}/notes/${noteId}`, { headers: authHeaders() })
    expect(removed.status()).toBe(204)

    await page.goto(`/canvas/${canvasId}`)
    const sheet = await openArchive(page)
    await sheet.getByRole('tab', { name: 'Удалённые' }).click()

    const row = item(sheet, title)
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: 'Восстановить' }).click()
    await expect(page.getByText('Заметка восстановлена', { exact: true })).toBeVisible()
    await expect(row).toHaveCount(0)

    const graph = await request.get(`${API_BASE}/canvases/${canvasId}/graph`, {
      headers: authHeaders(),
    })
    const node = ((await graph.json()).nodes as Array<{ id: string; x: number; y: number }>).find(
      (n) => n.id === noteId
    )
    expect(node, 'восстановленная нода должна быть в графе').toBeTruthy()
    expect(node!.x).toBe(260)
    expect(node!.y).toBe(100)
  })

  test('«Стереть навсегда» требует подтверждения с предупреждением о ссылках', async ({
    page,
    context,
    request,
  }) => {
    await loginViaApi(context)
    const canvasId = await createCanvas(request, 'E2E Archive Purge')
    const title = 'Стираемая заметка'
    const noteId = await createNote(request, canvasId, title)
    const relatedId = await createNote(request, canvasId, 'Связанная заметка', 400)
    const body = await request.put(`${API_BASE}/notes/${relatedId}/body`, {
      headers: authHeaders(),
      data: { bodyMd: `Ссылка [[${title}]]` },
    })
    expect(body.status()).toBe(200)
    const removed = await request.delete(`${API_BASE}/notes/${noteId}`, { headers: authHeaders() })
    expect(removed.status()).toBe(204)

    await page.goto(`/canvas/${canvasId}`)
    const sheet = await openArchive(page)
    await sheet.getByRole('tab', { name: 'Удалённые' }).click()

    const row = item(sheet, title)
    await row.getByRole('button', { name: 'Стереть навсегда' }).click()
    const warning = row.locator('.archive-panel__confirm')
    await expect(warning).toContainText('нельзя отменить')

    // Отмена ничего не меняет.
    await row.getByRole('button', { name: 'Отмена' }).click()
    await expect(row).toBeVisible()

    await row.getByRole('button', { name: 'Стереть навсегда' }).click()
    await row.getByRole('button', { name: 'Стереть навсегда' }).click()
    await expect(page.getByText('Заметка стёрта навсегда', { exact: true })).toBeVisible()
    await expect(row).toHaveCount(0)

    const archive = await request.get(`${API_BASE}/canvases/${canvasId}/archive?section=deleted`, {
      headers: authHeaders(),
    })
    const ids = ((await archive.json()).items as Array<{ id: string }>).map((n) => n.id)
    expect(ids).not.toContain(noteId)

    const graph = await request.get(`${API_BASE}/canvases/${canvasId}/graph`, {
      headers: authHeaders(),
    })
    const edges = (await graph.json()).edges as Array<{ state: string; label: string | null }>
    expect(edges.some((e) => e.state === 'removed' && e.label)).toBe(true)
  })
})
