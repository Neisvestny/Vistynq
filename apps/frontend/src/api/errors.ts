/**
 * Mapping ApiError → oshibki formy + tost (stranitsy auth-form).
 */
import { tGlobal } from '@/i18n'
import { ApiError } from './http'
import { notify } from './notify'

/**
 * details iz 422 VALIDATION_FAILED → po polyam formy; ostalnoe — tost.
 * Returns a short, user-safe message for an inline error banner.
 */
export function applyApiError(error: unknown, fieldErrors: Record<string, string>): string {
  if (!(error instanceof ApiError)) {
    const title = tGlobal('errors.unexpected')
    notify({ title, status: 'error' })

    return title
  }

  if (error.details && Object.keys(error.details).length > 0) {
    for (const [field, messages] of Object.entries(error.details)) {
      fieldErrors[field] = messages[0] ?? tGlobal('validation.invalidValue')
    }
    const title = tGlobal('errors.checkForm')
    notify({ title, status: 'error' })

    return title
  }

  if (error.code === 'NETWORK') {
    const title = tGlobal('errors.network')
    notify({ title, description: error.message, status: 'error' })

    return title
  }

  const title = tGlobal('errors.generic')
  notify({ title, description: error.message, status: 'error' })

  return title
}

/** Prosteyshaya klientskaya proverka email — simmetrichno bekend Validator (email-pravilo). */
export function isValidEmail(email: string): boolean {
  return /^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/.test(email.trim())
}
