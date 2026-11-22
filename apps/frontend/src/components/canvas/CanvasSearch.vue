<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { canvasApi } from '@/api/canvas'
import type { SearchHit } from '@/api/types'
import { useDebounceFn } from '@/composables/useDebounce'

const props = defineProps<{
  canvasId: string
}>()

const emit = defineEmits<{
  select: [nodeId: string]
}>()

const { t } = useI18n()
const rootEl = ref<HTMLElement | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)
const query = ref('')
const open = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const results = ref<SearchHit[]>([])
let requestSeq = 0

const hasQuery = computed(() => query.value.trim().length > 0)

async function runSearch(q: string) {
  const trimmed = q.trim()
  if (!trimmed) {
    results.value = []
    error.value = null
    loading.value = false
    return
  }

  const seq = ++requestSeq
  loading.value = true
  error.value = null
  try {
    const page = await canvasApi.search(props.canvasId, { q: trimmed, page: 1, perPage: 20 })
    if (seq !== requestSeq) return
    results.value = page.items
    open.value = true
  } catch (e) {
    if (seq !== requestSeq) return
    results.value = []
    error.value = e instanceof Error ? e.message : t('search.error')
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

const { run: debouncedSearch, cancel } = useDebounceFn((q: string) => {
  void runSearch(q)
}, 300)

watch(query, (q) => {
  if (!q.trim()) {
    cancel()
    results.value = []
    error.value = null
    open.value = false
    return
  }
  open.value = true
  debouncedSearch(q)
})

function onFocus() {
  if (hasQuery.value) open.value = true
}

function onSelect(id: string) {
  emit('select', id)
  open.value = false
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    open.value = false
    ;(event.target as HTMLElement | null)?.blur?.()
  }
}

function focus() {
  inputEl.value?.focus()
  inputEl.value?.select()
}

defineExpose({ focus })

function onDocumentPointerDown(event: PointerEvent) {
  if (!open.value) return
  const el = rootEl.value
  if (el && event.target instanceof Node && el.contains(event.target)) return
  open.value = false
}

if (typeof document !== 'undefined') {
  document.addEventListener('pointerdown', onDocumentPointerDown)
}

onBeforeUnmount(() => {
  cancel()
  if (typeof document !== 'undefined') {
    document.removeEventListener('pointerdown', onDocumentPointerDown)
  }
})
</script>

<template>
  <div ref="rootEl" class="canvas-search">
    <div class="canvas-search__field">
      <span class="canvas-search__icon" aria-hidden="true">⌕</span>
      <input
        ref="inputEl"
        v-model="query"
        class="canvas-search__input"
        type="search"
        :placeholder="t('search.placeholder')"
        :aria-label="t('search.aria')"
        @focus="onFocus"
        @keydown="onKeydown"
      />
    </div>

    <div v-if="open && hasQuery" class="canvas-search__dropdown" role="listbox">
      <div v-if="loading" class="canvas-search__state">{{ t('search.loading') }}</div>
      <div v-else-if="error" class="canvas-search__state canvas-search__state--error">
        {{ error }}
      </div>
      <div v-else-if="results.length === 0" class="canvas-search__state">
        {{ t('search.noResults') }}
      </div>
      <ul v-else class="canvas-search__list">
        <li v-for="hit in results" :key="hit.id" class="canvas-search__item">
          <button type="button" class="canvas-search__result" @click="onSelect(hit.id)">
            <span class="canvas-search__title">{{ hit.title || t('note.untitled') }}</span>
            <span class="canvas-search__meta">
              <span
                class="canvas-search__importance"
                :style="{
                  background: `var(--importance-${Math.min(5, Math.max(1, hit.importance || 3))})`,
                }"
                :title="t('search.importance', { value: hit.importance })"
              />
              <span v-if="hit.matchedTag" class="canvas-search__tag">#{{ hit.matchedTag }}</span>
            </span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
