<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { canvasApi } from '@/api/canvas'
import type { SearchHit } from '@/api/types'
import { useDebounceFn } from '@/composables/useDebounce'

const props = defineProps<{
  canvasId: string
}>()

const emit = defineEmits<{
  select: [nodeId: string]
}>()

const rootEl = ref<HTMLElement | null>(null)
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
    error.value = e instanceof Error ? e.message : 'Не удалось выполнить поиск'
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
        v-model="query"
        class="canvas-search__input"
        type="search"
        placeholder="Поиск по заметкам"
        aria-label="Поиск по заметкам"
        @focus="onFocus"
        @keydown="onKeydown"
      />
    </div>

    <div v-if="open && hasQuery" class="canvas-search__dropdown" role="listbox">
      <div v-if="loading" class="canvas-search__state">Поиск…</div>
      <div v-else-if="error" class="canvas-search__state canvas-search__state--error">
        {{ error }}
      </div>
      <div v-else-if="results.length === 0" class="canvas-search__state">Ничего не найдено</div>
      <ul v-else class="canvas-search__list">
        <li v-for="hit in results" :key="hit.id" class="canvas-search__item">
          <button type="button" class="canvas-search__result" @click="onSelect(hit.id)">
            <span class="canvas-search__title">{{ hit.title || 'Без названия' }}</span>
            <span class="canvas-search__meta">
              <span
                class="canvas-search__importance"
                :style="{
                  background: `var(--importance-${Math.min(5, Math.max(1, hit.importance || 3))})`,
                }"
                :title="`Важность ${hit.importance}`"
              />
              <span v-if="hit.matchedTag" class="canvas-search__tag">#{{ hit.matchedTag }}</span>
            </span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.canvas-search {
  position: relative;
  width: min(360px, 100%);
}

.canvas-search__field {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  height: 2rem;
  padding: 0 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm, 8px);
  background: var(--color-surface);
}

.canvas-search__icon {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.canvas-search__input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
}

.canvas-search__dropdown {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 45;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 10px);
  background: var(--color-surface-raised, var(--color-surface));
  box-shadow: var(--shadow-md);
  overflow: hidden;
}

.canvas-search__state {
  padding: 0.7rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-text-muted);
}

.canvas-search__state--error {
  color: var(--color-danger, #b42318);
}

.canvas-search__list {
  list-style: none;
  margin: 0;
  padding: 0.25rem;
  max-height: 320px;
  overflow: auto;
}

.canvas-search__result {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.45rem 0.5rem;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.canvas-search__result:hover {
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.04));
}

.canvas-search__title {
  font-size: 0.875rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.canvas-search__meta {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex: 0 0 auto;
}

.canvas-search__importance {
  width: 9px;
  height: 9px;
  border-radius: 999px;
}

.canvas-search__tag {
  font-size: 0.75rem;
  color: var(--color-accent);
  background: var(--color-accent-soft);
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
}
</style>
