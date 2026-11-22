import { beforeEach, describe, expect, it } from 'vitest'
import { setI18nLocale } from '@/i18n'

import {
  ARCHIVE_PER_PAGE,
  archiveEmptyMessage,
  archiveTotalPages,
  deleteWarning,
  purgeWarning,
  resolveArchivePage,
} from '@/lib/archive'

describe('archive helpers (R29)', () => {
  beforeEach(() => setI18nLocale('en'))
  it('archiveTotalPages vsegda vozvrashchaet khotya by 1 stranitsu', () => {
    expect(archiveTotalPages(0)).toBe(1)
    expect(archiveTotalPages(-5)).toBe(1)
    expect(archiveTotalPages(Number.NaN)).toBe(1)
  })

  it('archiveTotalPages okruglyaet vverkh i uvazhaet perPage', () => {
    expect(archiveTotalPages(ARCHIVE_PER_PAGE)).toBe(1)
    expect(archiveTotalPages(ARCHIVE_PER_PAGE + 1)).toBe(2)
    expect(archiveTotalPages(101)).toBe(6)
    expect(archiveTotalPages(101, 50)).toBe(3)
  })

  it('resolveArchivePage derzhit stranitsu v diapazone [1, totalPages]', () => {
    expect(resolveArchivePage(7, 3)).toBe(3)
    expect(resolveArchivePage(1, 3)).toBe(1)
    expect(resolveArchivePage(0, 3)).toBe(1)
    expect(resolveArchivePage(-4, 3)).toBe(1)
    expect(resolveArchivePage(Number.NaN, 3)).toBe(1)
    expect(resolveArchivePage(2, 0)).toBe(1)
  })

  it('archiveEmptyMessage razlichaet sektsii', () => {
    expect(archiveEmptyMessage('notRelevant')).toBe('No not relevant notes')
    expect(archiveEmptyMessage('deleted')).toBe('Trash is empty')
  })

  it('deleteWarning soderzhit zagolovok zametki', () => {
    expect(deleteWarning('Zametka')).toBe('Delete note "Zametka"? It will be moved to deleted.')
    expect(deleteWarning('')).toContain('Note')
  })

  it('purgeWarning preduprezhdaet o neobratimosti i slomannykh ssylkakh', () => {
    const text = purgeWarning('Vazhnaya')
    expect(text).toContain('"Vazhnaya"')
    expect(text).toContain('permanently')
    expect(text).toContain('cannot be undone')
  })
})
