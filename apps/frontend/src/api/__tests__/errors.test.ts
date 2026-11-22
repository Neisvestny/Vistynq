import { describe, expect, it, beforeEach, afterEach } from 'vitest'

import { ApiError } from '@/api/http'
import { applyApiError, isValidEmail } from '@/api/errors'
import { setNotifyHandler } from '@/api/notify'
import type { NotifyOptions } from '@/api/notify'
import { setI18nLocale } from '@/i18n'

describe('isValidEmail', () => {
  it('prinimaet obychnye adresa', () => {
    expect(isValidEmail('user@example.com')).toBe(true)
    expect(isValidEmail(' first.last@sub.domain.io ')).toBe(true)
  })

  it('otklonyaet musor', () => {
    expect(isValidEmail('')).toBe(false)
    expect(isValidEmail('no-at-sign')).toBe(false)
    expect(isValidEmail('two@@signs')).toBe(false)
    expect(isValidEmail('no-tld@example')).toBe(false)
    expect(isValidEmail('spaces in@example.com')).toBe(false)
  })
})

describe('applyApiError', () => {
  let toasts: NotifyOptions[] = []

  beforeEach(() => {
    toasts = []
    setI18nLocale('en')
    setNotifyHandler((options) => toasts.push(options))
  })

  afterEach(() => {
    setNotifyHandler(() => {})
  })

  it('raskladyvaet details 422 po polyam formy', () => {
    const fieldErrors: Record<string, string> = {}
    const error = new ApiError(422, 'VALIDATION_FAILED', 'Check the form', {
      email: ['email taken'],
      password: ['minimum 8 characters'],
    })

    applyApiError(error, fieldErrors)

    expect(fieldErrors).toEqual({ email: 'email taken', password: 'minimum 8 characters' })
    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Check the form')
  })

  it('NETWORK → tost «Net soedineniya»', () => {
    applyApiError(ApiError.network(), {})

    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('No connection')
  })

  it('ne-ApiError → tost «Nepredvidennaya oshibka»', () => {
    applyApiError(new Error('boom'), {})

    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Unexpected error')
  })

  it('prochie oshibki API → obshchiy tost s soobshcheniem', () => {
    applyApiError(new ApiError(409, 'CONFLICT', 'Email already registered'), {})

    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Error')
    expect(toasts[0]?.description).toBe('Email already registered')
  })
})
