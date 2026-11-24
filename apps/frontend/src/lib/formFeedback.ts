import { nextTick } from 'vue'

/** Move focus to the first field marked invalid by UiInput, after Vue re-renders. */
export async function focusFirstInvalidField(): Promise<void> {
  await nextTick()
  const field = document.querySelector<HTMLElement>(
    '.auth-form [aria-invalid="true"], .profile-page [aria-invalid="true"]'
  )
  field?.focus()
}
