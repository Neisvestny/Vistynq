<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { tagsApi } from '@/api/tags'
import { notify } from '@/api/notify'
import UiButton from '@/components/ui/UiButton.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

const props = withDefaults(
  defineProps<{
    open?: boolean
    highlightedTag?: string | null
  }>(),
  { open: false, highlightedTag: null }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:highlightedTag': [value: string | null]
  close: []
  changed: []
}>()

const { t } = useI18n()
const canvasStore = useCanvasStore()
const notesStore = useNotesStore()
const { canEditCanvas } = useAccessGuard()

const rootEl = ref<HTMLElement | null>(null)
const triggerEl = ref<HTMLElement | null>(null)
const panelEl = ref<HTMLElement | null>(null)
const busyTagId = ref<string | null>(null)
const newTagName = ref('')
const creating = ref(false)

const isOpen = computed(() => props.open)
const tags = computed(() => canvasStore.tags)

function setOpen(value: boolean) {
  emit('update:open', value)
  if (!value) {
    emit('update:highlightedTag', null)
    emit('close')
  }
}

function toggleOpen() {
  setOpen(!isOpen.value)
}

function setHighlight(name: string | null) {
  emit('update:highlightedTag', name)
}

function countNotesWithTag(name: string): number {
  return notesStore.nodes.reduce((acc, node) => acc + (node.tags.includes(name) ? 1 : 0), 0)
}

/** Let the page flush pending geometry and refresh the graph as one operation. */
function refreshGraph(): void {
  emit('changed')
}

async function createTag() {
  const canvasId = canvasStore.canvas?.id
  const name = newTagName.value.trim()
  if (!canvasId || !name || !canEditCanvas.value) return
  creating.value = true
  try {
    const tag = await tagsApi.create(canvasId, { name })
    canvasStore.tags = [...canvasStore.tags, tag].sort((a, b) => a.name.localeCompare(b.name, 'ru'))
    newTagName.value = ''
    notify({ title: t('tags.created'), status: 'success' })
  } catch (error) {
    notify({
      title: t('tags.createFailed'),
      description: error instanceof Error ? error.message : undefined,
      status: 'error',
    })
  } finally {
    creating.value = false
  }
}

async function renameTag(id: string, currentName: string) {
  if (!canEditCanvas.value) return
  const next = window.prompt(t('tags.renamePrompt'), currentName)
  if (next === null) return
  const name = next.trim()
  if (!name || name === currentName) return
  busyTagId.value = id
  try {
    const updated = await tagsApi.update(id, { name })
    canvasStore.tags = canvasStore.tags.map((tag) => (tag.id === id ? updated : tag))
    notesStore.renameTagInNodes(currentName, updated.name)
    notify({ title: t('tags.renamed'), status: 'success' })
  } catch (error) {
    notify({
      title: t('tags.renameFailed'),
      description: error instanceof Error ? error.message : undefined,
      status: 'error',
    })
  } finally {
    busyTagId.value = null
  }
}

async function recolorTag(id: string, currentColor: string) {
  if (!canEditCanvas.value) return
  const next = window.prompt(t('tags.colorPrompt'), currentColor || '#7c3aed')
  if (next === null) return
  const color = next.trim()
  if (!/^#[0-9a-fA-F]{6}$/.test(color)) {
    notify({
      title: t('tags.invalidColor'),
      description: t('tags.invalidColorDescription'),
      status: 'error',
    })
    return
  }
  busyTagId.value = id
  try {
    const updated = await tagsApi.update(id, { color })
    canvasStore.tags = canvasStore.tags.map((tag) => (tag.id === id ? updated : tag))
    notify({ title: t('tags.colorUpdated'), status: 'success' })
  } catch (error) {
    notify({
      title: t('tags.colorFailed'),
      description: error instanceof Error ? error.message : undefined,
      status: 'error',
    })
  } finally {
    busyTagId.value = null
  }
}

async function deleteTag(id: string, name: string) {
  if (!canEditCanvas.value) return
  const count = countNotesWithTag(name)
  const ok = window.confirm(t('tags.deleteConfirm', { count }))
  if (!ok) return
  busyTagId.value = id
  try {
    await tagsApi.remove(id)
    canvasStore.tags = canvasStore.tags.filter((tag) => tag.id !== id)
    notesStore.removeTagFromNodes(name)
    if (props.highlightedTag === name) setHighlight(null)
    notify({ title: t('tags.deleted'), status: 'success' })
    void refreshGraph()
  } catch (error) {
    notify({
      title: t('tags.deleteFailed'),
      description: error instanceof Error ? error.message : undefined,
      status: 'error',
    })
  } finally {
    busyTagId.value = null
  }
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
    void nextTick(() => panelEl.value?.querySelector<HTMLElement>('input, button')?.focus())
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
        {{ t('tags.title') }}
      </UiButton>
    </span>

    <div
      v-if="isOpen"
      ref="panelEl"
      class="canvas-popover__panel tag-menu"
      role="dialog"
      :aria-label="t('tags.aria')"
    >
      <div class="tag-menu__header">
        <span class="tag-menu__title">{{ t('tags.title') }}</span>
        <span class="tag-menu__hint">{{ t('tags.hint') }}</span>
      </div>

      <p v-if="!tags.length" class="tag-menu__empty">{{ t('tags.empty') }}</p>

      <ul v-else class="tag-menu__list">
        <li
          v-for="tag in tags"
          :key="tag.id"
          class="tag-menu__item"
          @mouseenter="setHighlight(tag.name)"
          @mouseleave="setHighlight(null)"
          @focusin="setHighlight(tag.name)"
          @focusout="setHighlight(null)"
        >
          <span class="tag-menu__chip">
            <span
              class="tag-menu__dot"
              :style="{ background: tag.color || 'var(--color-accent)' }"
            />
            <span class="tag-menu__name">{{ tag.name }}</span>
            <span class="tag-menu__count">{{ countNotesWithTag(tag.name) }}</span>
          </span>

          <span v-if="canEditCanvas" class="tag-menu__actions">
            <button
              type="button"
              class="tag-menu__action"
              :disabled="busyTagId === tag.id"
              @click="recolorTag(tag.id, tag.color)"
            >
              {{ t('tags.color') }}
            </button>
            <button
              type="button"
              class="tag-menu__action"
              :disabled="busyTagId === tag.id"
              @click="renameTag(tag.id, tag.name)"
            >
              {{ t('tags.name') }}
            </button>
            <button
              type="button"
              class="tag-menu__action tag-menu__action--danger"
              :disabled="busyTagId === tag.id"
              @click="deleteTag(tag.id, tag.name)"
            >
              {{ t('tags.delete') }}
            </button>
          </span>
        </li>
      </ul>

      <form v-if="canEditCanvas" class="tag-menu__create" @submit.prevent="createTag">
        <input
          v-model="newTagName"
          class="tag-menu__input"
          type="text"
          :placeholder="t('tags.newPlaceholder')"
          maxlength="40"
        />
        <UiButton type="submit" size="sm" :disabled="creating || !newTagName.trim()">{{
          t('tags.create')
        }}</UiButton>
      </form>
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
  left: 0;
  z-index: 40;
  min-width: 320px;
  max-width: 420px;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface-raised, var(--color-surface));
  box-shadow: var(--shadow-md);
}

.tag-menu__header {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  margin-bottom: 0.5rem;
}

.tag-menu__title {
  font-weight: 600;
  font-size: 0.9rem;
}

.tag-menu__hint {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.tag-menu__empty {
  margin: 0.25rem 0;
  font-size: 0.875rem;
  color: var(--color-text-muted);
}

.tag-menu__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
  max-height: 260px;
  overflow: auto;
}

.tag-menu__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.25rem 0.1rem;
}

.tag-menu__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}

.tag-menu__dot {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  flex: 0 0 auto;
}

.tag-menu__name {
  font-size: 0.875rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 140px;
}

.tag-menu__count {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.tag-menu__actions {
  display: inline-flex;
  gap: 0.25rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.tag-menu__action {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  border-radius: 6px;
  padding: 0.1rem 0.4rem;
  font-size: 0.75rem;
  cursor: pointer;
}

.tag-menu__action--danger {
  color: var(--color-danger, #b42318);
}

.tag-menu__action:disabled {
  opacity: 0.5;
  cursor: default;
}

.tag-menu__create {
  margin-top: 0.6rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-border-subtle, var(--color-border));
  display: flex;
  gap: 0.4rem;
}

.tag-menu__input {
  flex: 1;
  min-width: 0;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 0.35rem 0.5rem;
  font-size: 0.85rem;
  background: var(--color-surface);
  color: var(--color-text);
}
</style>
