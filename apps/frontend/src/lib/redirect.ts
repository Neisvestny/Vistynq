/** Accept only internal, same-origin paths from `?redirect=`. */
export function safeRedirectPath(value: unknown): string | null {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
    return null
  }
  if (/[\\]/.test(value) || /\s/.test(value)) return null
  try {
    const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost'
    const url = new URL(value, origin)
    if (url.origin !== origin) return null
    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    return null
  }
}
