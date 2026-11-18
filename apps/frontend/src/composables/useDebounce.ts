import { getCurrentScope, onScopeDispose, ref, watch, type Ref } from 'vue'

function tryOnScopeDispose(fn: () => void): void {
  if (getCurrentScope()) {
    onScopeDispose(fn)
  }
}

/** Дебаунс вызова функции; cancel/flush безопасны при размонтировании. */
export function useDebounceFn<TArgs extends unknown[]>(fn: (...args: TArgs) => void, delay = 300) {
  let timer: ReturnType<typeof setTimeout> | null = null
  let lastArgs: TArgs | null = null

  const cancel = (): void => {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
    lastArgs = null
  }

  const flush = (): void => {
    if (timer === null || lastArgs === null) return
    clearTimeout(timer)
    timer = null
    const args = lastArgs
    lastArgs = null
    fn(...args)
  }

  const run = (...args: TArgs): void => {
    lastArgs = args
    if (timer !== null) clearTimeout(timer)
    timer = setTimeout(() => {
      timer = null
      const args = lastArgs
      lastArgs = null
      if (args !== null) fn(...args)
    }, delay)
  }

  tryOnScopeDispose(cancel)

  return { run, cancel, flush }
}

/** Реактивное значение, обновляемое с дебаунсом после изменений источника. */
export function useDebouncedRef<T>(source: Ref<T>, delay = 300): Ref<T> {
  const debounced = ref(source.value) as Ref<T>
  const { run, cancel } = useDebounceFn((value: T) => {
    debounced.value = value
  }, delay)

  watch(
    source,
    (value) => {
      run(value)
    },
    { flush: 'sync' }
  )

  tryOnScopeDispose(cancel)

  return debounced
}
