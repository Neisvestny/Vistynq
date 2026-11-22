import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { NOTE_EDITOR_SIDE_KEY, useUiStore } from '@/stores/ui'

function stubStorage(initial: Record<string, string> = {}) {
  const data = new Map(Object.entries(initial))
  const storage = {
    getItem: vi.fn((key: string) => data.get(key) ?? null),
    setItem: vi.fn((key: string, value: string) => void data.set(key, value)),
    removeItem: vi.fn((key: string) => void data.delete(key)),
  }
  vi.stubGlobal('localStorage', storage)
  return { data, storage }
}

describe('ui store', () => {
  beforeEach(() => {
    stubStorage()
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('mobile menu is closed by default and can be toggled', () => {
    const store = useUiStore()
    expect(store.mobileMenuOpen).toBe(false)
    store.openMenu()
    expect(store.mobileMenuOpen).toBe(true)
    store.closeMenu()
    expect(store.mobileMenuOpen).toBe(false)
    store.toggleMenu()
    expect(store.mobileMenuOpen).toBe(true)
    store.toggleMenu()
    expect(store.mobileMenuOpen).toBe(false)
  })

  it('note editor side defaults to right and reads stored value', () => {
    expect(useUiStore().noteEditorSide).toBe('right')
    stubStorage({ [NOTE_EDITOR_SIDE_KEY]: 'left' })
    setActivePinia(createPinia())
    expect(useUiStore().noteEditorSide).toBe('left')
  })

  it('invalid stored side falls back to right', () => {
    stubStorage({ [NOTE_EDITOR_SIDE_KEY]: 'center' })
    setActivePinia(createPinia())
    expect(useUiStore().noteEditorSide).toBe('right')
  })

  it('set/toggle note editor side persists and no-ops on same value', () => {
    const { data, storage } = stubStorage({ 'vistynq:locale': 'ru' })
    setActivePinia(createPinia())
    const store = useUiStore()
    storage.setItem.mockClear()

    store.setNoteEditorSide('right')
    expect(storage.setItem).not.toHaveBeenCalled()

    store.toggleNoteEditorSide()
    expect(store.noteEditorSide).toBe('left')
    expect(data.get(NOTE_EDITOR_SIDE_KEY)).toBe('left')

    store.setNoteEditorSide('right')
    expect(store.noteEditorSide).toBe('right')
    expect(data.get(NOTE_EDITOR_SIDE_KEY)).toBe('right')
  })

  it('handles storage errors gracefully', () => {
    const storage = {
      getItem: vi.fn(() => {
        throw new Error('blocked')
      }),
      setItem: vi.fn(() => {
        throw new Error('blocked')
      }),
      removeItem: vi.fn(),
    }
    vi.stubGlobal('localStorage', storage)
    setActivePinia(createPinia())
    const store = useUiStore()
    expect(store.noteEditorSide).toBe('right')
    expect(() => store.setNoteEditorSide('left')).not.toThrow()
    expect(store.noteEditorSide).toBe('left')
  })
})
