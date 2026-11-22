import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { useDebounceFn, useDebouncedRef } from '../useDebounce'

describe('useDebounce', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('useDebounceFn vyzyvaet funktsiyu odin raz posle zaderzhki s poslednimi argumentami', () => {
    const fn = vi.fn()
    const { run } = useDebounceFn(fn, 300)

    run('a')
    run('b')
    vi.advanceTimersByTime(299)
    expect(fn).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(fn).toHaveBeenCalledTimes(1)
    expect(fn).toHaveBeenCalledWith('b')
  })

  it('cancel predotvrashchaet vyzov, flush vypolnyaet nemedlenno', () => {
    const fn = vi.fn()
    const debounced = useDebounceFn(fn, 300)

    debounced.run(1)
    debounced.cancel()
    vi.advanceTimersByTime(300)
    expect(fn).not.toHaveBeenCalled()

    debounced.run(2)
    debounced.flush()
    expect(fn).toHaveBeenCalledTimes(1)
    expect(fn).toHaveBeenCalledWith(2)
  })

  it('useDebouncedRef obnovlyaet znachenie posle zaderzhki', async () => {
    const source = ref(1)
    const debounced = useDebouncedRef(source, 200)

    expect(debounced.value).toBe(1)
    source.value = 2
    source.value = 3
    expect(debounced.value).toBe(1)

    vi.advanceTimersByTime(200)
    expect(debounced.value).toBe(3)
  })
})
