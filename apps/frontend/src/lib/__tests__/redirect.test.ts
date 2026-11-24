import { describe, expect, it } from 'vitest'
import { safeRedirectPath } from '@/lib/redirect'

describe('safeRedirectPath', () => {
  it('пропускает внутренние пути с query и hash', () => {
    expect(safeRedirectPath('/dashboard')).toBe('/dashboard')
    expect(safeRedirectPath('/canvas/abc?tab=notes#top')).toBe('/canvas/abc?tab=notes#top')
  })

  it('отклоняет внешние и протокол-относительные адреса', () => {
    expect(safeRedirectPath('https://evil.example/steal')).toBeNull()
    expect(safeRedirectPath('//evil.example/steal')).toBeNull()
    expect(safeRedirectPath('javascript:alert(1)')).toBeNull()
  })

  it('отклоняет пути с обратными слешами и пробелами', () => {
    expect(safeRedirectPath('/\\evil.example')).toBeNull()
    expect(safeRedirectPath('/dashboard\nSet-Cookie: x')).toBeNull()
  })

  it('отклоняет нестроковые значения', () => {
    expect(safeRedirectPath(undefined)).toBeNull()
    expect(safeRedirectPath(['/dashboard'])).toBeNull()
    expect(safeRedirectPath(42)).toBeNull()
  })
})
