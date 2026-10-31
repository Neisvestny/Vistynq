import { useToastManager } from 'reka-ui'

export type ToastStatus = 'default' | 'success' | 'error'

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
