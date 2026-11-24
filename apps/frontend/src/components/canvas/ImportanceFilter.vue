<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import { useNotesStore } from '@/stores/notes'

const props = withDefaults(
  defineProps<{
    open?: boolean
  }>(),
  { open: false }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  close: []
}>()

const { t } = useI18n()
const notesStore = useNotesStore()
const rootEl = ref<HTMLElement | null>(null)
const triggerEl = ref<HTMLElement | null>(null)
const panelEl = ref<HTMLElement | null>(null)

const levels = computed(
  () =>
    [
      { level: 1, label: t('importance.l1') },
      { level: 2, label: t('importance.l2') },
      { level: 3, label: t('importance.l3') },
      { level: 4, label: t('importance.l4') },
      { level: 5, label: t('importance.l5') },
    ] as const
)

const isOpen = computed(() => props.open)

function setOpen(value: boolean) {
  emit('update:open', value)
  if (!value) emit('close')
}

function toggleOpen() {
  setOpen(!isOpen.value)
}

function onToggleLevel(level: number) {
  notesStore.setImportanceEnabled(level, !notesStore.importanceFilter[level])
}

function onToggleIrrelevant() {
  notesStore.setShowIrrelevant(!notesStore.showIrrelevant)
}

function onReset() {
  notesStore.resetFilters()
}

function onDocumentPointerDown(event: PointerEvent) {
  if (!isOpen.value) return
  const el = rootEl.value
  if (el && event.target instanceof Node && el.contains(event.target)) return
  setOpen(false)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape' || !isOpen.value) return
  const target = event.target
  if (target instanceof Node && panelEl.value?.contains(target)) {
    event.stopPropagation()
    setOpen(false)
    triggerEl.value?.focus()
  }
}

watch(isOpen, (open, wasOpen) => {
  if (open) {
    void nextTick(() => panelEl.value?.querySelector<HTMLElement>('button, input')?.focus())
  } else if (wasOpen && panelEl.value?.contains(document.activeElement)) {
    triggerEl.value?.focus()
  }
})

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="rootEl" class="canvas-popover">
    <span ref="triggerEl" class="canvas-popover__trigger">
      <UiButton
        variant="ghost"
        size="sm"
        :aria-expanded="isOpen"
        aria-haspopup="dialog"
        @click="toggleOpen"
      >
        {{ t('importance.title') }}
      </UiButton>
    </span>

    <div
      v-if="isOpen"
      ref="panelEl"
      class="canvas-popover__panel importance-filter"
      role="dialog"
      :aria-label="t('importance.aria')"
    >
      <div class="importance-filter__header">
        <span class="importance-filter__title">{{ t('importance.title') }}</span>
        <button type="button" class="importance-filter__reset" @click="onReset">
          {{ t('importance.reset') }}
        </button>
      </div>

      <ul class="importance-filter__list">
        <li v-for="item in levels" :key="item.level" class="importance-filter__item">
          <label class="importance-filter__label">
            <input
              type="checkbox"
              :checked="notesStore.importanceFilter[item.level] !== false"
              @change="onToggleLevel(item.level)"
            />
            <span
              class="importance-filter__dot"
              :style="{ background: `var(--importance-${item.level})` }"
            />
            <span>{{ item.label }}</span>
          </label>
        </li>
      </ul>

      <div class="importance-filter__divider" />

      <label class="importance-filter__label importance-filter__label--toggle">
        <input type="checkbox" :checked="notesStore.showIrrelevant" @change="onToggleIrrelevant" />
        <span>{{ t('importance.showIrrelevant') }}</span>
      </label>

      <p class="importance-filter__hint">{{ t('importance.hint') }}</p>
    </div>
  </div>
</template>

<style scoped>
.canvas-popover {
  position: relative;
}

.canvas-popover__trigger {
  display: inline-flex;
}

.canvas-popover__panel {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 40;
  min-width: 240px;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface-raised, var(--color-surface));
  box-shadow: var(--shadow-md);
}

.importance-filter__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.importance-filter__title {
  font-weight: 600;
  font-size: 0.9rem;
}

.importance-filter__reset {
  border: none;
  background: transparent;
  color: var(--color-accent);
  font-size: 0.8rem;
  cursor: pointer;
  padding: 0;
}

.importance-filter__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
}

.importance-filter__label {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-text);
  cursor: pointer;
}

.importance-filter__dot {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  flex: 0 0 auto;
}

.importance-filter__divider {
  height: 1px;
  background: var(--color-border-subtle, var(--color-border));
  margin: 0.6rem 0;
}

.importance-filter__hint {
  margin: 0.5rem 0 0;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}
</style>
