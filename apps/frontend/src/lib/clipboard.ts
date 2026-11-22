import { notify } from '@/api/notify'

export async function copyText(text: string, successTitle = 'Скопировано'): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      notify({ title: successTitle, status: 'success' })
      return true
    }
  } catch {
    // fallback below
  }

  try {
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.setAttribute('readonly', '')
    textarea.style.position = 'fixed'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.select()
    textarea.setSelectionRange(0, text.length)
    const ok = document.execCommand('copy')
    document.body.removeChild(textarea)
    if (ok) {
      notify({ title: successTitle, status: 'success' })
      return true
    }
  } catch {
    // ignore
  }

  notify({ title: 'Не удалось скопировать', status: 'error' })
  return false
}
