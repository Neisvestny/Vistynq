/**
 * Маппинг ApiError → ошибки формы + тост (страницы auth-форм).
 */
import { ApiError } from './http'
import { notify } from './notify'

/** details из 422 VALIDATION_FAILED → по полям формы; остальное — тост. */
export function applyApiError(error: unknown, fieldErrors: Record<string, string>): void {
  if (!(error instanceof ApiError)) {
    notify({ title: 'Непредвиденная ошибка', status: 'error' })

    return
  }

  if (error.details && Object.keys(error.details).length > 0) {
    for (const [field, messages] of Object.entries(error.details)) {
      fieldErrors[field] = messages[0] ?? 'некорректное значение'
    }
    notify({ title: 'Проверьте форму', status: 'error' })

    return
  }

  if (error.code === 'NETWORK') {
    notify({ title: 'Нет соединения', description: error.message, status: 'error' })

    return
  }

  notify({ title: 'Ошибка', description: error.message, status: 'error' })
}

/** Простейшая клиентская проверка email — симметрично бэкенд Validator (email-правило). */
export function isValidEmail(email: string): boolean {
  return /^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/.test(email.trim())
}
