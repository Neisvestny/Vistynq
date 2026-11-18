import { getCurrentScope, onScopeDispose, ref } from 'vue'

export interface DropFileOptions {
  acceptExtensions?: string[]
  acceptMime?: string[]
  maxSizeBytes?: number
}

export interface DroppedFilesResult {
  accepted: File[]
  rejected: Array<{ file: File; reason: string }>
}

/**
 * Обработка drag&drop файлов для целевой области.
 * Возвращает нормализованный результат без сетевых запросов (R31 подключит API).
 */
export function useDropFiles(options: DropFileOptions = {}) {
  const isDragging = ref(false)
  let depth = 0

  function reset(): void {
    depth = 0
    isDragging.value = false
  }

  function onDragEnter(event: DragEvent): void {
    event.preventDefault()
    depth += 1
    isDragging.value = true
  }

  function onDragOver(event: DragEvent): void {
    event.preventDefault()
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = 'copy'
    }
  }

  function onDragLeave(event: DragEvent): void {
    event.preventDefault()
    depth = Math.max(0, depth - 1)
    if (depth === 0) isDragging.value = false
  }

  function matches(file: File): string | null {
    const exts = options.acceptExtensions?.map((e) => e.toLowerCase().replace(/^\./, ''))
    if (exts && exts.length > 0) {
      const name = file.name.toLowerCase()
      const ok = exts.some((ext) => name.endsWith(`.${ext}`))
      if (!ok) return 'unsupported-extension'
    }

    const mimes = options.acceptMime
    if (mimes && mimes.length > 0) {
      const ok = mimes.some((m) =>
        m.endsWith('/*') ? file.type.startsWith(m.slice(0, -1)) : file.type === m
      )
      if (!ok) return 'unsupported-type'
    }

    if (typeof options.maxSizeBytes === 'number' && file.size > options.maxSizeBytes) {
      return 'too-large'
    }

    return null
  }

  function onDrop(event: DragEvent): DroppedFilesResult {
    event.preventDefault()
    reset()
    const files = Array.from(event.dataTransfer?.files ?? [])
    const accepted: File[] = []
    const rejected: DroppedFilesResult['rejected'] = []

    for (const file of files) {
      const reason = matches(file)
      if (reason) rejected.push({ file, reason })
      else accepted.push(file)
    }

    return { accepted, rejected }
  }

  if (getCurrentScope()) {
    onScopeDispose(reset)
  }

  return { isDragging, onDragEnter, onDragOver, onDragLeave, onDrop, reset }
}
