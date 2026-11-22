/**
 * Глобальное UI-состояние (docs/architecture.md §stores, R04).
 * Сейчас — мобильное меню (ленивый sheet из TheSiteHeader), положение
 * панели редактора заметки (R28) и место расширения под фильтры (R29/R34).
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'

export type NoteEditorSide = 'left' | 'right'

/** Не чувствительная настройка UI — допускается localStorage. */
export const NOTE_EDITOR_SIDE_KEY = 'vistynq:note-editor-side'

function readNoteEditorSide(): NoteEditorSide {
  try {
    const value = globalThis.localStorage?.getItem(NOTE_EDITOR_SIDE_KEY)
    return value === 'left' || value === 'right' ? value : 'right'
  } catch {
    return 'right'
  }
}

function writeNoteEditorSide(side: NoteEditorSide): void {
  try {
    globalThis.localStorage?.setItem(NOTE_EDITOR_SIDE_KEY, side)
  } catch {
    // Приватный режим/заблокированный storage — preference просто не сохранится.
  }
}

export const useUiStore = defineStore('ui', () => {
  const mobileMenuOpen = ref(false)
  const noteEditorSide = ref<NoteEditorSide>(readNoteEditorSide())

  function openMenu(): void {
    mobileMenuOpen.value = true
  }

  function closeMenu(): void {
    mobileMenuOpen.value = false
  }

  function toggleMenu(): void {
    mobileMenuOpen.value = !mobileMenuOpen.value
  }

  function setNoteEditorSide(side: NoteEditorSide): void {
    if (noteEditorSide.value === side) return
    noteEditorSide.value = side
    writeNoteEditorSide(side)
  }

  function toggleNoteEditorSide(): void {
    setNoteEditorSide(noteEditorSide.value === 'right' ? 'left' : 'right')
  }

  return {
    mobileMenuOpen,
    noteEditorSide,
    openMenu,
    closeMenu,
    toggleMenu,
    setNoteEditorSide,
    toggleNoteEditorSide,
  }
})
