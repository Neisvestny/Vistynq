<script setup lang="ts">
/**
 * Canvas archive (R29, docs/ui-ux.md "Archive panel"): two paginated
 * sections - "Not relevant" and "Deleted" - with lifecycle actions.
 *
 * Component calls API and manages its own list; emits `changed` after
 * a successful action so the page can sync graph/store.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import { notesApi } from '@/api/notes'
import type { ArchiveSection, Note } from '@/api/types'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import {
  ARCHIVE_PER_PAGE,
  archiveTotalPages,
  resolveArchivePage,
  type ArchiveChange,
} from '@/lib/archive'
import { tagIdsToNames } from '@/lib/noteMapping'
import { useCanvasStore } from '@/stores/canvas'
import { useUiStore } from '@/stores/ui'

const props = withDefaults(
  defineProps<{
    open?: boolean
    canvasId?: string | null
  }>(),
  { open: false, canvasId: null }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  changed: [change: ArchiveChange]
  /** Parent must flush pending node geometry before an archive API call. */
  'before-action': [done: () => void]
}>()

type ActionKind = ArchiveChange['kind']
type ConfirmKind = 'soft-delete' | 'purge'

const { t, locale } = useI18n()
const store = useCanvasStore()
const ui = useUiStore()
const { canEditCanvas } = useAccessGuard()

const section = ref<ArchiveSection>('notRelevant')
const page = ref(1)
const items = ref<Note[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref<ApiError | null>(null)
const busyId = ref<string | null>(null)
const confirming = ref<{ id: string; kind: ConfirmKind } | null>(null)
const listRef = ref<HTMLElement | null>(null)

/** Request sequence number: prevent stale response from overwriting list. */
let requestId = 0

const totalPages = computed(() => archiveTotalPages(total.value))
const isBusy = computed(() => busyId.value !== null)

function noteTitle(note: Note): string {
  return note.title || t('note.untitled')
}

function tagNames(note: Note): string[] {
  return tagIdsToNames(note.tagIds, store.tags)
}

function formatDate(value?: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const intlLocale = locale.value === 'en' ? 'en-US' : 'ru-RU'
  return new Intl.DateTimeFormat(intlLocale, { dateStyle: 'medium' }).format(date)
}

async function load(target = page.value): Promise<void> {
  if (!props.canvasId) return
  const current = ++requestId
  loading.value = true
  error.value = null
  try {
    const response = await canvasApi.archive(props.canvasId, {
      section: section.value,
      page: target,
      perPage: ARCHIVE_PER_PAGE,
    })
    if (current !== requestId) return
    page.value = response.page
    total.value = response.total
    items.value = response.items

    // After deleting the last record on a page, server may return empty items -
    // roll back to the last existing page (one retry).
    if (items.value.length === 0 && target > totalPages.value) {
      const last = resolveArchivePage(target, totalPages.value)
      if (last !== target) {
        const fallback = await canvasApi.archive(props.canvasId, {
          section: section.value,
          page: last,
          perPage: ARCHIVE_PER_PAGE,
        })
        if (current !== requestId) return
        page.value = fallback.page
        total.value = fallback.total
        items.value = fallback.items
      }
    }
  } catch (e) {
    if (current !== requestId) return
    error.value = e instanceof ApiError ? e : ApiError.network()
    items.value = []
    total.value = 0
  } finally {
    if (current === requestId) loading.value = false
  }
}

function resetAndLoad(): void {
  if (!canEditCanvas.value) return
  section.value = 'notRelevant'
  page.value = 1
  items.value = []
  total.value = 0
  error.value = null
  confirming.value = null
  void load(1)
}

function switchSection(next: ArchiveSection): void {
  if (section.value === next || isBusy.value) return
  section.value = next
  page.value = 1
  items.value = []
  total.value = 0
  error.value = null
  confirming.value = null
  void load(1)
}

function goToPage(next: number): void {
  if (loading.value || isBusy.value) return
  const target = resolveArchivePage(next, totalPages.value)
  if (target === page.value) return
  confirming.value = null
  void load(target)
  listRef.value?.scrollTo({ top: 0, behavior: 'smooth' })
}

const ACTION_ERRORS = computed<Record<ActionKind, string>>(() => ({
  'make-relevant': t('archive.makeRelevantError'),
  'soft-delete': t('archive.softDeleteError'),
  restore: t('archive.restoreError'),
  purge: t('archive.purgeError'),
}))

async function runAction(note: Note, kind: ActionKind): Promise<void> {
  if (isBusy.value || loading.value) return
  busyId.value = note.id
  await new Promise<void>((resolve) => emit('before-action', resolve))
  try {
    switch (kind) {
      case 'make-relevant': {
        await notesApi.updateManifest(note.id, { isRelevant: true })
        emit('changed', { kind: 'make-relevant', noteId: note.id })
        notify({ title: t('archive.returned'), status: 'success' })
        break
      }
      case 'soft-delete': {
        await notesApi.remove(note.id)
        emit('changed', { kind: 'soft-delete', noteId: note.id })
        notify({ title: t('archive.deleted'), status: 'success' })
        break
      }
      case 'restore': {
        const restored = await notesApi.restore(note.id)
        emit('changed', { kind: 'restore', note: restored })
        notify({ title: t('archive.restored'), status: 'success' })
        break
      }
      case 'purge': {
        await notesApi.purge(note.id)
        emit('changed', { kind: 'purge', noteId: note.id })
        notify({ title: t('archive.purged'), status: 'success' })
        break
      }
    }
    confirming.value = null
    await load(page.value)
  } catch (e) {
    const apiError = e instanceof ApiError ? e : ApiError.network()
    if (kind === 'restore' && apiError.status === 410) {
      notify({ title: t('archive.restoreGone'), status: 'error' })
      await load(page.value)
    } else if ((kind === 'restore' || kind === 'purge') && apiError.status === 404) {
      notify({ title: t('archive.notAvailable'), status: 'error' })
      await load(page.value)
    } else {
      notify({
        title: ACTION_ERRORS.value[kind],
        description: apiError.message,
        status: 'error',
      })
    }
  } finally {
    busyId.value = null
  }
}

function requestAction(note: Note, kind: ConfirmKind): void {
  if (isBusy.value || loading.value) return
  confirming.value = { id: note.id, kind }
}

function cancelAction(): void {
  confirming.value = null
}

function confirmedKind(note: Note): ConfirmKind | null {
  return confirming.value?.id === note.id ? confirming.value.kind : null
}

function confirmText(note: Note): string {
  const kind = confirmedKind(note)
  const title = noteTitle(note)
  return kind === 'purge'
    ? t('archive.purgeWarning', { title })
    : t('archive.deleteWarning', { title })
}

function confirmLabel(note: Note): string {
  return confirmedKind(note) === 'purge' ? t('archive.purge') : t('archive.softDelete')
}

function confirmAction(note: Note): void {
  const kind = confirmedKind(note)
  if (kind) void runAction(note, kind === 'purge' ? 'purge' : 'soft-delete')
}

watch(
  () => props.open,
  (open) => {
    if (open) resetAndLoad()
  },
  // Parent mounts panel via v-if, so open=true already at creation.
  { immediate: true }
)

watch(
  () => props.canvasId,
  () => {
    if (props.open) resetAndLoad()
  }
)
</script>

<template>
  <UiSheet
    :open="open"
    :side="ui.noteEditorSide"
    :wide="true"
    :title="t('archive.title')"
    @update:open="emit('update:open', $event)"
  >
    <div class="archive-panel">
      <div class="archive-panel__tabs" role="tablist" :aria-label="t('archive.sectionsAria')">
        <button
          type="button"
          role="tab"
          class="archive-panel__tab"
          :class="{ 'archive-panel__tab--active': section === 'notRelevant' }"
          :aria-selected="section === 'notRelevant'"
          :disabled="loading || isBusy"
          @click="switchSection('notRelevant')"
        >
          {{ t('archive.notRelevantTab') }}
        </button>
        <button
          type="button"
          role="tab"
          class="archive-panel__tab"
          :class="{ 'archive-panel__tab--active': section === 'deleted' }"
          :aria-selected="section === 'deleted'"
          :disabled="loading || isBusy"
          @click="switchSection('deleted')"
        >
          {{ t('archive.deletedTab') }}
        </button>
      </div>

      <div ref="listRef" class="archive-panel__list">
        <div v-if="loading" class="archive-panel__skeleton" aria-busy="true">
          <div v-for="row in 3" :key="row" class="archive-panel__skeleton-row" />
        </div>

        <div v-else-if="error" class="archive-panel__error">
          <p class="archive-panel__error-text">{{ error.message }}</p>
          <UiButton variant="ghost" size="sm" @click="load(page)">{{ t('common.retry') }}</UiButton>
        </div>

        <p v-else-if="items.length === 0" class="archive-panel__empty">
          {{ section === 'deleted' ? t('archive.emptyDeleted') : t('archive.emptyNotRelevant') }}
        </p>

        <article v-for="note in items" :key="note.id" class="archive-panel__item">
          <div class="archive-panel__item-main">
            <p class="archive-panel__item-title" :title="noteTitle(note)">{{ noteTitle(note) }}</p>
            <div class="archive-panel__item-meta">
              <span>{{ t('archive.importance', { value: note.importance }) }}</span>
              <span v-if="section === 'deleted' && formatDate(note.deletedAt)">
                {{ t('archive.deletedAt', { date: formatDate(note.deletedAt) }) }}
              </span>
            </div>
            <div v-if="tagNames(note).length" class="archive-panel__tags">
              <span v-for="name in tagNames(note)" :key="name" class="archive-panel__tag">
                {{ name }}
              </span>
            </div>
          </div>

          <template v-if="confirmedKind(note)">
            <p class="archive-panel__confirm">{{ confirmText(note) }}</p>
            <div class="archive-panel__actions">
              <UiButton size="sm" :disabled="loading || isBusy" @click="confirmAction(note)">
                {{ confirmLabel(note) }}
              </UiButton>
              <UiButton
                variant="ghost"
                size="sm"
                :disabled="loading || isBusy"
                @click="cancelAction"
              >
                {{ t('common.cancel') }}
              </UiButton>
            </div>
          </template>

          <div v-else class="archive-panel__actions">
            <template v-if="section === 'notRelevant'">
              <UiButton
                size="sm"
                :disabled="loading || isBusy"
                @click="runAction(note, 'make-relevant')"
              >
                {{ t('archive.makeRelevant') }}
              </UiButton>
              <UiButton
                variant="ghost"
                size="sm"
                :disabled="loading || isBusy"
                @click="requestAction(note, 'soft-delete')"
              >
                {{ t('archive.softDelete') }}
              </UiButton>
            </template>
            <template v-else>
              <UiButton size="sm" :disabled="loading || isBusy" @click="runAction(note, 'restore')">
                {{ t('archive.restore') }}
              </UiButton>
              <UiButton
                variant="ghost"
                size="sm"
                class="archive-panel__danger"
                :disabled="loading || isBusy"
                @click="requestAction(note, 'purge')"
              >
                {{ t('archive.purge') }}
              </UiButton>
            </template>
          </div>
        </article>
      </div>

      <nav
        v-if="totalPages > 1"
        class="archive-panel__pagination"
        :aria-label="t('archive.paginationAria')"
      >
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="loading || isBusy || page <= 1"
          @click="goToPage(page - 1)"
        >
          {{ t('common.back') }}
        </UiButton>
        <span class="archive-panel__pagination-info">
          {{ t('archive.pageInfo', { page, totalPages, total }) }}
        </span>
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="loading || isBusy || page >= totalPages"
          @click="goToPage(page + 1)"
        >
          {{ t('common.forward') }}
        </UiButton>
      </nav>
    </div>
  </UiSheet>
</template>
