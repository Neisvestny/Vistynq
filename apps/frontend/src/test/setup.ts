import { i18n } from '@/i18n'

// Keep tests deterministic: default to Russian locale regardless of environment.
i18n.global.locale.value = 'ru'
if (typeof document !== 'undefined') {
  document.documentElement.lang = 'ru'
}
