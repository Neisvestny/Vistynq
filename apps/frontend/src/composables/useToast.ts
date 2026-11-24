import { useToastManager } from 'reka-ui'
import type { ToastStatus } from '@/api/notify'

export type { ToastStatus } from '@/api/notify'

interface ToastOptions {
  title: string
  description?: string
  status?: ToastStatus
  duration?: number
}

export function useToast() {
  const manager = useToastManager()

  function notify({ title, description, status = 'default', duration }: ToastOptions) {
    return manager.add({
      title,
      description,
      status,
      ...(duration !== undefined ? { duration } : {}),
    })
  }

  return { notify }
}
