import { createI18n } from 'vue-i18n'
import ru from '@/locales/ru.json'
import en from '@/locales/en.json'
import type { AppLocale } from '@/i18n'

export function createTestI18n(locale: AppLocale = 'ru') {
  return createI18n({
    legacy: false,
    globalInjection: true,
    locale,
    fallbackLocale: 'ru',
    messages: { ru, en },
    missingWarn: false,
    fallbackWarn: false,
  })
}
