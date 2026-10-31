/**
 * Мост тостов для кода вне компонентов (store, router-guards).
 *
 * reka-ui useToastManager() работает только внутри ToastProvider; здесь —
 * module-level хендлер, который регистрирует ToastBridge.vue (см. App.vue).
 */
export type ToastStatus = 'default' | 'success' | 'error'

export interface NotifyOptions {
  title: string
  description?: string
  status?: ToastStatus
}

type NotifyHandler = (options: NotifyOptions) => void

let handler: NotifyHandler | null = null

export function setNotifyHandler(fn: NotifyHandler): void {
  handler = fn
}

export function notify(options: NotifyOptions): void {
  handler?.(options)
}
