/**
 * Глобальное UI-состояние (docs/architecture.md §stores, R04).
 * Сейчас — мобильное меню (ленивый sheet из TheSiteHeader); место расширения
 * под панели/фильтры (R27/R29/R34).
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'

export const useUiStore = defineStore('ui', () => {
  const mobileMenuOpen = ref(false)

  function openMenu(): void {
    mobileMenuOpen.value = true
  }

  function closeMenu(): void {
    mobileMenuOpen.value = false
  }

  function toggleMenu(): void {
    mobileMenuOpen.value = !mobileMenuOpen.value
  }

  return { mobileMenuOpen, openMenu, closeMenu, toggleMenu }
})
