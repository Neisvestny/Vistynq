import { describe, expect, it, beforeEach, afterEach } from 'vitest'

import { ApiError } from '@/api/http'
import { applyApiError, isValidEmail } from '@/api/errors'
import { setNotifyHandler } from '@/api/notify'
import type { NotifyOptions } from '@/api/notify'

describe('isValidEmail', () => {
  it('принимает обычные адреса', () => {
    expect(isValidEmail('user@example.com')).toBe(true)
    expect(isValidEmail(' first.last@sub.domain.io ')).toBe(true)
  })

  it('отклоняет мусор', () => {
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
    setNotifyHandler((options) => toasts.push(options))
  })

  afterEach(() => {
    setNotifyHandler(() => {})
  })

  it('раскладывает details 422 по полям формы', () => {
    const fieldErrors: Record<string, string> = {}
    const error = new ApiError(422, 'VALIDATION_FAILED', 'Проверьте форму', {
      email: ['email занят'],
      password: ['минимум 8 символов'],
    })

    applyApiError(error, fieldErrors)

    expect(fieldErrors).toEqual({ email: 'email занят', password: 'минимум 8 символов' })
    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Проверьте форму')
  })

  it('NETWORK → тост «Нет соединения»', () => {
    applyApiError(ApiError.network(), {})

    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Нет соединения')
  })

  it('не-ApiError → тост «Непредвиденная ошибка»', () => {
    applyApiError(new Error('boom'), {})

    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Непредвиденная ошибка')
  })

  it('прочие ошибки API → общий тост с сообщением', () => {
    applyApiError(new ApiError(409, 'CONFLICT', 'Email уже зарегистрирован'), {})

    expect(toasts).toHaveLength(1)
    expect(toasts[0]?.title).toBe('Ошибка')
    expect(toasts[0]?.description).toBe('Email уже зарегистрирован')
  })
})
