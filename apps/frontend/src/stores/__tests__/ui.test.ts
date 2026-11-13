import { describe, expect, it, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useUiStore } from '@/stores/ui'

describe('ui store (R04)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
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
})
