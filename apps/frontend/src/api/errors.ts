/**
 * Mapping ApiError → oshibki formy + tost (stranitsy auth-form).
 */
import { tGlobal } from '@/i18n'
import { ApiError } from './http'
import { notify } from './notify'

/** details iz 422 VALIDATION_FAILED → po polyam formy; ostalnoe — tost. */
export function applyApiError(error: unknown, fieldErrors: Record<string, string>): void {
  if (!(error instanceof ApiError)) {
    notify({ title: tGlobal('errors.unexpected'), status: 'error' })

    return
  }

  if (error.details && Object.keys(error.details).length > 0) {
    for (const [field, messages] of Object.entries(error.details)) {
      fieldErrors[field] = messages[0] ?? tGlobal('validation.invalidValue')
    }
    notify({ title: tGlobal('errors.checkForm'), status: 'error' })

    return
  }

  if (error.code === 'NETWORK') {
    notify({ title: tGlobal('errors.network'), description: error.message, status: 'error' })

    return
  }

  notify({ title: tGlobal('errors.generic'), description: error.message, status: 'error' })
}

/** Prosteyshaya klientskaya proverka email — simmetrichno bekend Validator (email-pravilo). */
export function isValidEmail(email: string): boolean {
  return /^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/.test(email.trim())
}
