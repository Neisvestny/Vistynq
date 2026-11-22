/**
 * Преобразование ответов API заметок в GraphNode стора `notes` (R13/R28).
 * В `Note`/`NoteDetail` теги приходят id-ами, в графе — именами.
 */
import type { GraphNode, Note, NoteDetail, Tag } from '@/api/types'

function clampImportance(value: number | undefined): number {
  if (!Number.isFinite(value) || value === undefined) return 3
  return Math.min(5, Math.max(1, Math.round(value)))
}

export function tagIdsToNames(tagIds: string[] | undefined, tags: Tag[]): string[] {
  if (!tagIds?.length) return []
  const byId = new Map(tags.map((tag) => [tag.id, tag.name]))

  return Array.from(
    new Set(tagIds.map((id) => byId.get(id)).filter((name): name is string => Boolean(name)))
  )
}

/** Базовая маппинг-функция для ответов create/derive/import/restore (тип Note). */
export function noteToGraphNode(
  note: Pick<
    Note,
    | 'id'
    | 'title'
    | 'importance'
    | 'isRelevant'
    | 'visibility'
    | 'x'
    | 'y'
    | 'width'
    | 'deletedAt'
    | 'authorId'
    | 'tagIds'
  >,
  tags: Tag[] = [],
  fallbackAuthorId = ''
): GraphNode {
  return {
    id: note.id,
    title: note.title || 'Без названия',
    importance: clampImportance(note.importance),
    isRelevant: note.isRelevant,
    visibility: note.visibility ?? 'public',
    x: note.x,
    y: note.y,
    width: note.width || 220,
    deletedAt: note.deletedAt ?? null,
    tags: tagIdsToNames(note.tagIds, tags),
    authorId: note.authorId || fallbackAuthorId,
  }
}

/**
 * После сохранения манифеста/тела: геометрию и автора берём из уже
 * загруженной ноды (там может быть свежий локальный drag/resize).
 */
export function noteDetailToGraphNode(
  detail: NoteDetail,
  existing: GraphNode | undefined,
  tags: Tag[]
): GraphNode {
  const base = noteToGraphNode(detail, tags, existing?.authorId)

  return {
    ...base,
    x: Number.isFinite(detail.x) ? detail.x : (existing?.x ?? 0),
    y: Number.isFinite(detail.y) ? detail.y : (existing?.y ?? 0),
    width: detail.width > 0 ? detail.width : (existing?.width ?? 220),
    authorId: detail.authorId || existing?.authorId || '',
    tags: detail.tagIds ? base.tags : (existing?.tags ?? []),
  }
}
