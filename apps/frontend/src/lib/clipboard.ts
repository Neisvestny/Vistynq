import { tGlobal } from '@/i18n'
import { notify } from '@/api/notify'

export async function copyText(text: string, successTitle?: string): Promise<boolean> {
  const resolvedSuccessTitle = successTitle ?? tGlobal('common.copied')
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      notify({ title: resolvedSuccessTitle, status: 'success' })
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
      notify({ title: resolvedSuccessTitle, status: 'success' })
      return true
    }
  } catch {
    // ignore
  }

  notify({ title: tGlobal('common.copyError'), status: 'error' })
  return false
}
