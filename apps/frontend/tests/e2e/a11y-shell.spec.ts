import { test, expect } from '@playwright/test'

/**
 * Проверки каркаса, не требующие бэкенда: доступная навигация, язык, 404.
 * Запускаются на тех же baseURL/локali, что и остальные e2e-спеки.
 */
test.describe('доступный каркас', () => {
  test('skip-link появляется при фокусе и переводит в main', async ({ page }) => {
    await page.goto('/')

    const skip = page.getByRole('link', { name: /Перейти к содержимому|Skip to content/ })
    await page.keyboard.press('Tab')
    await expect(skip).toBeFocused()
    await expect(skip).toBeVisible()

    await page.keyboard.press('Enter')
    await expect(page.locator('main#main')).toBeFocused()
  })

  test('переключатель языка обновляет lang и подписи', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator('html')).toHaveAttribute('lang', 'ru')

    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/map/i)
  })

  test('лендинг содержит единственный h1 и иллюстрацию графа', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expect(page.locator('.hero__art svg')).toBeVisible()
  })

  test('404 даёт основную область и переход к канвасам', async ({ page }) => {
    await page.goto('/this-page-does-not-exist')
    await expect(page.locator('main#main')).toBeVisible()
    await expect(page.getByRole('link', { name: 'К канвасам' })).toBeVisible()
  })
})
