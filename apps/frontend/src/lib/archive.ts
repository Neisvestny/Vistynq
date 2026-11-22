/**
 * Чистая логика архив-панели (R29): пагинация, тексты подтверждений и
 * тип события об изменении жизненного цикла заметки.
 *
 * Вынесена из компонента, чтобы покрыть unit-тестами без @vue/test-utils.
 */
import type { ArchiveSection, Note } from '@/api/types'

export const ARCHIVE_PER_PAGE = 20

/** Успешно выполненное действие в архиве — клиент синхронизирует граф/стор. */
export type ArchiveChange =
  | { kind: 'make-relevant'; noteId: string }
  | { kind: 'soft-delete'; noteId: string }
  | { kind: 'restore'; note: Note }
  | { kind: 'purge'; noteId: string }

/** Всегда хотя бы одна страница — пустой архив тоже имеет страницу 1. */
export function archiveTotalPages(total: number, perPage = ARCHIVE_PER_PAGE): number {
  const size = Number.isFinite(perPage) && perPage > 0 ? perPage : ARCHIVE_PER_PAGE
  if (!Number.isFinite(total) || total <= 0) return 1
  return Math.max(1, Math.ceil(total / size))
}

/** Ограничить запрошенную страницу диапазоном [1, totalPages]. */
export function resolveArchivePage(requested: number, totalPages: number): number {
  const max = Number.isFinite(totalPages) && totalPages > 0 ? Math.floor(totalPages) : 1
  const value = Number.isFinite(requested) ? Math.floor(requested) : 1
  return Math.min(Math.max(value, 1), max)
}

export function archiveEmptyMessage(section: ArchiveSection): string {
  return section === 'deleted' ? 'Корзина пуста' : 'Нет неактуальных заметок'
}

function titleOf(title: string): string {
  return title || 'Без названия'
}

export function deleteWarning(title: string): string {
  return `Удалить заметку «${titleOf(title)}»? Она появится в разделе «Удалённые».`
}

export function purgeWarning(title: string): string {
  return (
    `Стереть заметку «${titleOf(title)}» навсегда? Действие необратимо. ` +
    'Связанные заметки потеряют ссылку: у косвенных ссылок появится пометка «ссылка удалена».'
  )
}
