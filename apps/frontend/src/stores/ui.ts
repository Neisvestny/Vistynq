/**
 * Globalnoe UI-sostoyanie (docs/architecture.md §stores, R04).
 * Seychas — mobilnoe menyu (lenivyy sheet iz TheSiteHeader), polozhenie
 * paneli redaktora zametki (R28), lokal (R34) i mesto rasshireniya pod filtry (R29).
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { detectLocale, isAppLocale, setI18nLocale, type AppLocale } from '@/i18n'

export type NoteEditorSide = 'left' | 'right'

/** Ne chuvstvitelnaya nastroyka UI — dopuskaetsya localStorage. */
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
    // Privatnyy rezhim/zablokirovannyy storage — preference prosto ne sokhranitsya.
  }
}

export const useUiStore = defineStore('ui', () => {
  const mobileMenuOpen = ref(false)
  const noteEditorSide = ref<NoteEditorSide>(readNoteEditorSide())
  const locale = ref<AppLocale>(detectLocale())

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

  function setLocale(next: AppLocale): void {
    if (!isAppLocale(next) || locale.value === next) return
    locale.value = next
    setI18nLocale(next)
  }

  // Keep i18n in sync on store init (detectLocale already applied in i18n module).
  setI18nLocale(locale.value)

  return {
    mobileMenuOpen,
    noteEditorSide,
    locale,
    openMenu,
    closeMenu,
    toggleMenu,
    setNoteEditorSide,
    toggleNoteEditorSide,
    setLocale,
  }
})
