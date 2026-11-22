import { getCurrentScope, onScopeDispose, type Ref } from 'vue'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import { tGlobal } from '@/i18n'
import type { NodeGeometryPatch } from '@/stores/notes'
import { useNotesStore } from '@/stores/notes'
import { notify } from '@/api/notify'

interface SavedGeometry {
  x: number
  y: number
  width: number
}

function sameGeometry(a: SavedGeometry, b: SavedGeometry): boolean {
  return a.x === b.x && a.y === b.y && a.width === b.width
}

function tryOnScopeDispose(fn: () => void): void {
  if (getCurrentScope()) {
    onScopeDispose(fn)
  }
}

/**
 * Debauns-sokhranenie pozitsiy/shiriny nod (R26): 500 ms vo vremya izmeneniy,
 * nemedlennyy flush na zavershenie zhesta, serializatsiya zaprosov na nodu.
 */
export function useNodePositionSync(canvasId: Ref<string | null>) {
  const notesStore = useNotesStore()

  const timers = new Map<string, ReturnType<typeof setTimeout>>()
  const pending = new Map<string, SavedGeometry>()
  const lastSaved = new Map<string, SavedGeometry>()
  const snapshotBeforeGesture = new Map<string, SavedGeometry>()
  const inFlight = new Set<string>()
  const dirty = new Set<string>()

  function readCurrent(id: string): SavedGeometry | null {
    const node = notesStore.getNodeById(id)
    if (!node) return null
    return { x: node.x, y: node.y, width: node.width }
  }

  /** Zafiksirovat servernoe/aktualnoe sostoyanie nod (posle zagruzki grafa). */
  function syncFromNodes(): void {
    cancelAll()
    lastSaved.clear()
    snapshotBeforeGesture.clear()
    dirty.clear()
    for (const node of notesStore.nodes) {
      lastSaved.set(node.id, { x: node.x, y: node.y, width: node.width })
    }
  }

  function clearTimer(id: string): void {
    const timer = timers.get(id)
    if (timer !== undefined) {
      clearTimeout(timer)
      timers.delete(id)
    }
  }

  function cancelAll(): void {
    for (const timer of timers.values()) clearTimeout(timer)
    timers.clear()
    pending.clear()
    inFlight.clear()
  }

  /** Nachalo zhesta: zapomnit tochku otkata, esli eshchyo ne v "gryaznom" sostoyanii. */
  function beginGesture(id: string): void {
    if (snapshotBeforeGesture.has(id)) return
    const current = readCurrent(id)
    if (current) snapshotBeforeGesture.set(id, current)
  }

  /** Zaplanirovat sokhranenie (debounce 500 ms). */
  function scheduleSave(id: string, delay = 500): void {
    const current = readCurrent(id)
    if (!current) return
    const saved = lastSaved.get(id)
    if (saved && sameGeometry(saved, current)) {
      pending.delete(id)
      dirty.delete(id)
      clearTimer(id)
      if (!inFlight.has(id)) snapshotBeforeGesture.delete(id)
      return
    }

    dirty.add(id)
    pending.set(id, current)
    clearTimer(id)
    timers.set(
      id,
      setTimeout(() => {
        timers.delete(id)
        void flushOne(id)
      }, delay)
    )
  }

  async function flushOne(id: string): Promise<void> {
    clearTimer(id)
    const cid = canvasId.value
    if (!cid) return

    const payload = pending.get(id) ?? readCurrent(id)
    if (!payload) return

    const saved = lastSaved.get(id)
    if (saved && sameGeometry(saved, payload)) {
      pending.delete(id)
      dirty.delete(id)
      snapshotBeforeGesture.delete(id)
      return
    }

    if (inFlight.has(id)) {
      // Posle zaversheniya tekushchego zaprosa povtorim s aktualnym pending.
      pending.set(id, payload)
      return
    }

    inFlight.add(id)
    pending.delete(id)

    try {
      await canvasApi.patchNodePosition(cid, id, {
        x: payload.x,
        y: payload.y,
        width: payload.width,
      })
      lastSaved.set(id, payload)
      snapshotBeforeGesture.delete(id)
      dirty.delete(id)

      // Esli vo vremya zaprosa prishli novye izmeneniya — otpravim ikh.
      if (pending.has(id)) {
        inFlight.delete(id)
        void flushOne(id)
        return
      }
    } catch (error) {
      pending.delete(id)
      const fallback = snapshotBeforeGesture.get(id) ?? lastSaved.get(id)
      if (fallback) {
        notesStore.updateNodeGeometry(id, fallback)
        lastSaved.set(id, fallback)
      }
      snapshotBeforeGesture.delete(id)
      dirty.delete(id)

      const message =
        error instanceof ApiError && error.message
          ? error.message
          : tGlobal('canvas.saveLayoutErrorDescription')
      notify({ title: tGlobal('canvas.saveLayoutError'), description: message, status: 'error' })
    } finally {
      inFlight.delete(id)
      if (pending.has(id) && !timers.has(id) && !inFlight.has(id)) {
        void flushOne(id)
      }
    }
  }

  async function flushAll(): Promise<void> {
    const ids = new Set<string>([...timers.keys(), ...pending.keys(), ...dirty])
    for (const id of ids) {
      clearTimer(id)
      // Prostavim aktualnoe sostoyanie v pending, esli ego net
      if (!pending.has(id)) {
        const current = readCurrent(id)
        if (current) pending.set(id, current)
      }
      await flushOne(id)
    }
  }

  /** Primenit lokalnoe izmenenie geometrii i zaplanirovat sokhranenie. */
  function applyAndSchedule(id: string, patch: NodeGeometryPatch): void {
    beginGesture(id)
    notesStore.updateNodeGeometry(id, patch)
    scheduleSave(id)
  }

  /** Primenit lokalnoe izmenenie i nemedlenno sokhranit (konets zhesta). */
  function applyAndFlush(id: string, patch: NodeGeometryPatch): void {
    beginGesture(id)
    notesStore.updateNodeGeometry(id, patch)
    const current = readCurrent(id)
    if (current) pending.set(id, current)
    void flushOne(id)
  }

  tryOnScopeDispose(() => {
    // Best-effort: snimem taymery; nezavershyonnye zaprosy mogut ne uspet.
    cancelAll()
  })

  return {
    syncFromNodes,
    beginGesture,
    scheduleSave,
    flushOne,
    flushAll,
    applyAndSchedule,
    applyAndFlush,
    cancelAll,
  }
}
