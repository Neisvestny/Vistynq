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
  const inFlight = new Map<string, Promise<void>>()
  const dirty = new Set<string>()
  let epoch = 0

  function readCurrent(id: string): SavedGeometry | null {
    const node = notesStore.getNodeById(id)
    if (!node) return null
    return { x: node.x, y: node.y, width: node.width }
  }

  /** Zafiksirovat servernoe/aktualnoe sostoyanie nod (posle zagruzki grafa). */
  function syncFromNodes(): void {
    epoch += 1
    for (const timer of timers.values()) clearTimeout(timer)
    timers.clear()
    pending.clear()
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
    epoch += 1
    for (const timer of timers.values()) clearTimeout(timer)
    timers.clear()
    pending.clear()
    dirty.clear()
    inFlight.clear()
    snapshotBeforeGesture.clear()
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

    const running = inFlight.get(id)
    if (running) {
      // Posle zaversheniya tekushchego zaprosa povtorim s aktualnym pending.
      pending.set(id, payload)
      await running
      return
    }

    const requestEpoch = epoch
    const task = (async () => {
      try {
        await canvasApi.patchNodePosition(cid, id, {
          x: payload.x,
          y: payload.y,
          width: payload.width,
        })
        if (requestEpoch !== epoch) return
        lastSaved.set(id, payload)
        snapshotBeforeGesture.delete(id)
        dirty.delete(id)
      } catch (error) {
        if (requestEpoch !== epoch) return
        // Rollback only if no newer geometry is queued for this node.
        if (!pending.has(id)) {
          const fallback = snapshotBeforeGesture.get(id) ?? lastSaved.get(id)
          if (fallback) {
            notesStore.updateNodeGeometry(id, fallback)
            lastSaved.set(id, fallback)
          }
        }
        snapshotBeforeGesture.delete(id)
        dirty.delete(id)

        const message =
          error instanceof ApiError && error.message
            ? error.message
            : tGlobal('canvas.saveLayoutErrorDescription')
        notify({ title: tGlobal('canvas.saveLayoutError'), description: message, status: 'error' })
      } finally {
        // A replacement request cannot start while this one is in inFlight,
        // so the entry is always ours.
        inFlight.delete(id)
      }
    })()
    inFlight.set(id, task)
    pending.delete(id)

    await task
    if (requestEpoch !== epoch) return
    // Esli vo vremya zaprosa prishli novye izmeneniya — otpravim ikh.
    if (pending.has(id) && !timers.has(id)) await flushOne(id)
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
    // Dozhdemsya zaprosov, kotorye uzhe byli v polete do nachala flush.
    await Promise.all([...new Set(inFlight.values())])
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
    // Ne otmenyaem uzhe ukedshie zaprosy: flushAll() v potrebitеле dzhdet ikh.
    for (const timer of timers.values()) clearTimeout(timer)
    timers.clear()
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
