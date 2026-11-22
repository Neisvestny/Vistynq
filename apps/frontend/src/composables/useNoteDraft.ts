/**
 * Pure draft state for note editor (R28).
 * Builds minimal diff for PATCH/PUT without side effects -
 * easy to test and reuse in the modal.
 */
import type { UpdateNoteManifestPayload } from '@/api/notes'
import type { NoteDetail, NoteVisibility } from '@/api/types'
import { normalizeMarkdown } from '@/lib/editorMarkdown'
import { tGlobal } from '@/i18n'

export interface ManifestDraft {
  title: string
  importance: number
  isRelevant: boolean
  tagIds: string[]
  visibility: NoteVisibility
}

export interface NoteDraft {
  manifest: ManifestDraft
  bodyMd: string
}

export interface SavePlan {
  manifestPatch?: UpdateNoteManifestPayload
  bodyMd?: string
  visibility?: NoteVisibility
}

export function draftFromDetail(detail: NoteDetail): NoteDraft {
  return {
    manifest: {
      title: detail.title,
      importance: detail.importance,
      isRelevant: detail.isRelevant,
      tagIds: [...detail.tagIds],
      visibility: detail.visibility,
    },
    bodyMd: normalizeMarkdown(detail.bodyMd),
  }
}

export function isDraftDirty(before: NoteDraft, after: NoteDraft): boolean {
  return JSON.stringify(before) !== JSON.stringify(after)
}

export function validateManifest(manifest: ManifestDraft): string[] {
  const errors: string[] = []
  const title = manifest.title.trim()
  if (title === '') errors.push(tGlobal('note.titleRequired'))
  if (title.length > 200) errors.push(tGlobal('note.titleTooLong'))
  if (
    !Number.isInteger(manifest.importance) ||
    manifest.importance < 1 ||
    manifest.importance > 5
  ) {
    errors.push(tGlobal('note.importanceRange'))
  }

  return errors
}

function sameIds(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((id, index) => id === b[index])
}

/** Only actually changed fields - no extra PATCH/PUT. */
export function buildSavePlan(before: NoteDraft, after: NoteDraft): SavePlan {
  const patch: UpdateNoteManifestPayload = {}
  const a = before.manifest
  const b = after.manifest

  const title = b.title.trim()
  if (title !== a.title.trim()) patch.title = title
  if (b.importance !== a.importance) patch.importance = b.importance
  if (b.isRelevant !== a.isRelevant) patch.isRelevant = b.isRelevant
  if (!sameIds(a.tagIds, b.tagIds)) patch.tagIds = [...b.tagIds]

  const plan: SavePlan = {}
  if (Object.keys(patch).length > 0) plan.manifestPatch = patch
  if (after.bodyMd !== before.bodyMd) plan.bodyMd = normalizeMarkdown(after.bodyMd)
  if (b.visibility !== a.visibility) plan.visibility = b.visibility

  return plan
}
