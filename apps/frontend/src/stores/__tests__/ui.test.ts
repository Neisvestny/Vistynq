import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { NOTE_EDITOR_SIDE_KEY, useUiStore } from '@/stores/ui'

function stubStorage(initial: Record<string, string> = {}): Map<string, string> {
  const data = new Map(Object.entries(initial))
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => data.get(key) ?? null,
    setItem: (key: string, value: string) => void data.set(key, value),
    removeItem: (key: string) => void data.delete(key),
  })

  return data
}

describe('ui store (R04)', () => {
  beforeEach(() => {
    stubStorage()
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('мобильное меню закрыто по умолчанию', () => {
    expect(useUiStore().mobileMenuOpen).toBe(false)
  })

  it('openMenu/closeMenu выставляют флаг', () => {
    const store = useUiStore()

    store.openMenu()
    expect(store.mobileMenuOpen).toBe(true)

    store.closeMenu()
    expect(store.mobileMenuOpen).toBe(false)
  })

  it('toggleMenu инвертирует состояние', () => {
    const store = useUiStore()

    store.toggleMenu()
    expect(store.mobileMenuOpen).toBe(true)

    store.toggleMenu()
    expect(store.mobileMenuOpen).toBe(false)
  })

  it('панель редактора по умолчанию справа', () => {
    expect(useUiStore().noteEditorSide).toBe('right')
  })

  it('переключатель стороны панели сохраняет выбор в localStorage', () => {
    const data = stubStorage()
    setActivePinia(createPinia())
    const store = useUiStore()

    store.toggleNoteEditorSide()
    expect(store.noteEditorSide).toBe('left')
    expect(data.get(NOTE_EDITOR_SIDE_KEY)).toBe('left')

    store.setNoteEditorSide('right')
    expect(store.noteEditorSide).toBe('right')
    expect(data.get(NOTE_EDITOR_SIDE_KEY)).toBe('right')
  })

  it('битое значение localStorage не ломает сторону панели', () => {
    stubStorage({ [NOTE_EDITOR_SIDE_KEY]: 'center' })
    setActivePinia(createPinia())

    expect(useUiStore().noteEditorSide).toBe('right')
  })
})
