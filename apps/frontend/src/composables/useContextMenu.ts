import { getCurrentScope, onScopeDispose, reactive, shallowRef, watch } from 'vue'

export interface ContextMenuPoint {
  x: number
  y: number
}

export interface ContextMenuState {
  open: boolean
  x: number
  y: number
}

function tryOnScopeDispose(fn: () => void): void {
  if (getCurrentScope()) {
    onScopeDispose(fn)
  }
}

function toPoint(target: MouseEvent | ContextMenuPoint): ContextMenuPoint {
  if ('clientX' in target) {
    return { x: target.clientX, y: target.clientY }
  }
  return { x: target.x, y: target.y }
}

/**
 * Sostoyanie kontekstnogo menyu (koordinaty + proizvolnyy kontekst).
 * Zakryvaetsya po Esc, kliku vne, skrollu/resize. Dlya klikov vnutri menyu
 * peredayte konteyner cherez menuEl.
 */
export function useContextMenu<T = unknown>() {
  const state = reactive<ContextMenuState>({
    open: false,
    x: 0,
    y: 0,
  })
  const context = shallowRef<T | null>(null)
  /** DOM-element menyu: pointerdown vnutri nego ne zakryvaet menyu. */
  const menuEl = shallowRef<HTMLElement | null>(null)

  function close(): void {
    state.open = false
    context.value = null
  }

  function open(target: MouseEvent | ContextMenuPoint, nextContext?: T): void {
    if ('preventDefault' in target && typeof target.preventDefault === 'function') {
      target.preventDefault()
    }
    const point = toPoint(target)
    state.x = point.x
    state.y = point.y
    context.value = nextContext ?? null
    state.open = true
  }

  function toggle(target: MouseEvent | ContextMenuPoint, nextContext?: T): void {
    if (state.open) {
      close()
    } else {
      open(target, nextContext)
    }
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') close()
  }

  function onPointerDown(event: PointerEvent): void {
    const el = menuEl.value
    if (el && event.target instanceof Node && el.contains(event.target)) return
    close()
  }

  function onViewportChange(): void {
    close()
  }

  let listening = false

  function addListeners(): void {
    if (listening || typeof window === 'undefined') return
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('pointerdown', onPointerDown, true)
    window.addEventListener('scroll', onViewportChange, true)
    window.addEventListener('resize', onViewportChange)
    listening = true
  }

  function removeListeners(): void {
    if (!listening || typeof window === 'undefined') return
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('pointerdown', onPointerDown, true)
    window.removeEventListener('scroll', onViewportChange, true)
    window.removeEventListener('resize', onViewportChange)
    listening = false
  }

  watch(
    () => state.open,
    (isOpen) => {
      if (isOpen) addListeners()
      else removeListeners()
    }
  )

  tryOnScopeDispose(() => {
    removeListeners()
  })

  return { state, context, menuEl, open, close, toggle }
}
