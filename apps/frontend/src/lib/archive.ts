/**
 * Pure archive panel logic (R29): pagination, confirmation texts and
 * note lifecycle change event type.
 *
 * Extracted from component for unit testing without @vue/test-utils.
 */
import type { ArchiveSection, Note } from '@/api/types'
import { tGlobal } from '@/i18n'

export const ARCHIVE_PER_PAGE = 20

/** Successfully completed archive action - client syncs graph/store. */
export type ArchiveChange =
  | { kind: 'make-relevant'; noteId: string }
  | { kind: 'soft-delete'; noteId: string }
  | { kind: 'restore'; note: Note }
  | { kind: 'purge'; noteId: string }

/** Always at least one page - empty archive also has page 1. */
export function archiveTotalPages(total: number, perPage = ARCHIVE_PER_PAGE): number {
  const size = Number.isFinite(perPage) && perPage > 0 ? perPage : ARCHIVE_PER_PAGE
  if (!Number.isFinite(total) || total <= 0) return 1
  return Math.max(1, Math.ceil(total / size))
}

/** Clamp requested page to [1, totalPages]. */
export function resolveArchivePage(requested: number, totalPages: number): number {
  const max = Number.isFinite(totalPages) && totalPages > 0 ? Math.floor(totalPages) : 1
  const value = Number.isFinite(requested) ? Math.floor(requested) : 1
  return Math.min(Math.max(value, 1), max)
}

export function archiveEmptyMessage(section: ArchiveSection): string {
  return section === 'deleted'
    ? tGlobal('archive.emptyDeleted')
    : tGlobal('archive.emptyNotRelevant')
}

function titleOf(title: string): string {
  return title || tGlobal('note.untitled')
}

export function deleteWarning(title: string): string {
  return tGlobal('archive.deleteWarning', { title: titleOf(title) })
}

export function purgeWarning(title: string): string {
  return tGlobal('archive.purgeWarning', { title: titleOf(title) })
}
