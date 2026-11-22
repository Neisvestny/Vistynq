import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import type { AccessRole } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useCanvasStore } from '@/stores/canvas'

export interface NoteAccessLike {
  authorId?: string
  myRole?: AccessRole | 'owner' | 'editor' | 'viewer'
}

/**
 * UI-proverki prav na osnove auth i aktivnogo kanvasa/zametki.
 * Ne zamenyaet servernye proverki; pri otsutstvii konteksta — bezopasnyy zapret.
 */
export function useAccessGuard(note?: MaybeRefOrGetter<NoteAccessLike | null | undefined>) {
  const auth = useAuthStore()
  const canvasStore = useCanvasStore()

  const userId = computed(() => auth.user?.id ?? null)
  const canvasRole = computed<AccessRole>(() => canvasStore.canvas?.access.myRole ?? 'none')
  const canvasOwnerId = computed(() => canvasStore.canvas?.ownerId ?? null)

  const isOwner = computed(() => canvasRole.value === 'owner')
  const isEditor = computed(() => canvasRole.value === 'editor')
  const isViewer = computed(() => canvasRole.value === 'viewer')
  const hasCanvasAccess = computed(() => canvasRole.value !== 'none')
  const isCanvasOwnerById = computed(
    () =>
      userId.value !== null && canvasOwnerId.value !== null && userId.value === canvasOwnerId.value
  )

  const canReadCanvas = computed(() => hasCanvasAccess.value)
  const canEditCanvas = computed(() => isOwner.value || isEditor.value)
  const canManageCanvasAccess = computed(() => isOwner.value || isCanvasOwnerById.value)
  const canReadOnly = computed(() => isViewer.value)

  function canEditNote(target?: NoteAccessLike | null): boolean {
    const n = target ?? toValue(note) ?? null
    if (!n) return false
    if (n.myRole === 'owner' || n.myRole === 'editor') return true
    if (n.myRole === 'viewer' || n.myRole === 'none') return false

    const uid = userId.value
    if (uid && n.authorId && uid === n.authorId) return true
    if (isOwner.value) return true
    if (isEditor.value) return true
    return false
  }

  function canManageNoteAccess(target?: NoteAccessLike | null): boolean {
    const n = target ?? toValue(note) ?? null
    if (!n) return false
    if (n.myRole === 'owner') return true
    const uid = userId.value
    if (uid && n.authorId && uid === n.authorId) return true
    return isOwner.value || isCanvasOwnerById.value
  }

  /**
   * Pravo dvigat/resayzit nodu (docs/ui-ux.md, matritsa prav):
   * owner/author nody, editor kanvasa, owner kanvasa — da; viewer i editor tolko zametki — net.
   */
  function canMoveResizeNode(target?: NoteAccessLike | null): boolean {
    if (canEditCanvas.value) return true
    const n = target ?? toValue(note) ?? null
    if (!n) return false
    const uid = userId.value
    if (uid && n.authorId && uid === n.authorId) return true
    return false
  }

  const canEditCurrentNote = computed(() => canEditNote())

  return {
    userId,
    canvasRole,
    isOwner,
    isEditor,
    isViewer,
    hasCanvasAccess,
    canReadCanvas,
    canEditCanvas,
    canManageCanvasAccess,
    canReadOnly,
    canEditNote,
    canManageNoteAccess,
    canMoveResizeNode,
    canEditCurrentNote,
  }
}
