// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ru from '@/locales/ru.json'
import en from '@/locales/en.json'

const STORAGE_KEY = 'vistynq:locale'

function flattenKeys(value: unknown, prefix = ''): string[] {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return prefix ? [prefix] : []
  }
  return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) => {
    const next = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object' && !Array.isArray(v)) return flattenKeys(v, next)
    return [next]
  })
}

describe('i18n', () => {
  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
    document.documentElement.lang = 'ru'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('ru and en have same key set', () => {
    expect(flattenKeys(ru).sort()).toEqual(flattenKeys(en).sort())
  })

  it('detects locale from localStorage', async () => {
    localStorage.setItem(STORAGE_KEY, 'en')
    const mod = await import('@/i18n')
    expect(mod.detectLocale()).toBe('en')
    expect(mod.i18n.global.locale.value).toBe('en')
  })

  it('falls back to navigator.language then ru', async () => {
    vi.stubGlobal('navigator', { language: 'en-US' })
    const mod = await import('@/i18n')
    expect(mod.detectLocale()).toBe('en')

    vi.resetModules()
    localStorage.clear()
    vi.stubGlobal('navigator', { language: 'fr-FR' })
    const mod2 = await import('@/i18n')
    expect(mod2.detectLocale()).toBe('ru')
  })

  it('setI18nLocale persists and updates html lang', async () => {
    const mod = await import('@/i18n')
    mod.setI18nLocale('en')
    expect(localStorage.getItem(STORAGE_KEY)).toBe('en')
    expect(document.documentElement.lang).toBe('en')
    expect(mod.i18n.global.locale.value).toBe('en')
  })
})
