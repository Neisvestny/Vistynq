import { createI18n } from 'vue-i18n'
import ru from '@/locales/ru.json'
import en from '@/locales/en.json'

export const SUPPORTED_LOCALES = ['ru', 'en'] as const
export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const LOCALE_STORAGE_KEY = 'vistynq:locale'

export function isAppLocale(value: unknown): value is AppLocale {
  return value === 'ru' || value === 'en'
}

export function detectLocale(): AppLocale {
  try {
    const stored = globalThis.localStorage?.getItem(LOCALE_STORAGE_KEY)
    if (isAppLocale(stored)) return stored
  } catch {
    // ignore storage errors
  }

  const nav = typeof navigator !== 'undefined' ? navigator.language.toLowerCase() : ''
  if (nav.startsWith('ru')) return 'ru'
  if (nav.startsWith('en')) return 'en'
  return 'ru'
}

export function applyDocumentLocale(locale: AppLocale): void {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = locale
  }
}

export function persistLocale(locale: AppLocale): void {
  try {
    globalThis.localStorage?.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // ignore storage errors
  }
}

const initialLocale = detectLocale()
applyDocumentLocale(initialLocale)

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: initialLocale,
  fallbackLocale: 'ru',
  messages: { ru, en },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: import.meta.env.DEV,
})

export function setI18nLocale(locale: AppLocale): void {
  i18n.global.locale.value = locale
  persistLocale(locale)
  applyDocumentLocale(locale)
}

export function tGlobal(key: string, params?: Record<string, unknown>): string {
  return i18n.global.t(key, params ?? {}) as string
}
