/**
 * Most tostov dlya koda vne komponentov (store, router-guards).
 *
 * reka-ui useToastManager() rabotaet tolko vnutri ToastProvider; zdes —
 * module-level khendler, kotoryy registriruet ToastBridge.vue (sm. App.vue).
 */
export type ToastStatus = 'default' | 'success' | 'error'

export interface NotifyOptions {
  title: string
  description?: string
  status?: ToastStatus
}

type NotifyHandler = (options: NotifyOptions) => void

let handler: NotifyHandler | null = null

/** Pass null on unmount so a stale bridge never receives notifications. */
export function setNotifyHandler(fn: NotifyHandler | null): void {
  handler = fn
}

export function notify(options: NotifyOptions): void {
  handler?.(options)
}
