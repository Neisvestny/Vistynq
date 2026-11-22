<script setup lang="ts">
/**
 * Архив канваса (R29, docs/ui-ux.md §«Панель архива»): две постраничные
 * секции — «Неактуальные» и «Удалённые» — с действиями жизненного цикла.
 *
 * Компонент сам вызывает API и управляет своим списком; после успешного
 * действия эмитит `changed`, чтобы страница синхронизировала граф/стор.
 */
import { computed, ref, watch } from 'vue'
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
  archiveEmptyMessage,
  archiveTotalPages,
  deleteWarning,
  purgeWarning,
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
}>()

type ActionKind = ArchiveChange['kind']
type ConfirmKind = 'soft-delete' | 'purge'

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

/** Порядковый номер запроса: не даём устаревшему ответу перезаписать список. */
let requestId = 0

const totalPages = computed(() => archiveTotalPages(total.value))
const isBusy = computed(() => busyId.value !== null)

function noteTitle(note: Note): string {
  return note.title || 'Без названия'
}

function tagNames(note: Note): string[] {
  return tagIdsToNames(note.tagIds, store.tags)
}

function formatDate(value?: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(date)
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

    // После удаления последней записи страницы сервер вернёт пустой items —
    // откатываемся на последнюю существующую страницу (один повтор).
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

const ACTION_ERRORS: Record<ActionKind, string> = {
  'make-relevant': 'Не удалось вернуть заметку',
  'soft-delete': 'Не удалось удалить заметку',
  restore: 'Не удалось восстановить заметку',
  purge: 'Не удалось стереть заметку',
}

async function runAction(note: Note, kind: ActionKind): Promise<void> {
  if (isBusy.value || loading.value) return
  busyId.value = note.id
  try {
    switch (kind) {
      case 'make-relevant': {
        await notesApi.updateManifest(note.id, { isRelevant: true })
        emit('changed', { kind: 'make-relevant', noteId: note.id })
        notify({ title: 'Заметка возвращена на канвас', status: 'success' })
        break
      }
      case 'soft-delete': {
        await notesApi.remove(note.id)
        emit('changed', { kind: 'soft-delete', noteId: note.id })
        notify({ title: 'Заметка удалена', status: 'success' })
        break
      }
      case 'restore': {
        const restored = await notesApi.restore(note.id)
        emit('changed', { kind: 'restore', note: restored })
        notify({ title: 'Заметка восстановлена', status: 'success' })
        break
      }
      case 'purge': {
        await notesApi.purge(note.id)
        emit('changed', { kind: 'purge', noteId: note.id })
        notify({ title: 'Заметка стёрта навсегда', status: 'success' })
        break
      }
    }
    confirming.value = null
    await load(page.value)
  } catch (e) {
    const apiError = e instanceof ApiError ? e : ApiError.network()
    if (kind === 'restore' && apiError.status === 410) {
      notify({ title: 'Заметка стёрта навсегда и не может быть восстановлена', status: 'error' })
      await load(page.value)
    } else if ((kind === 'restore' || kind === 'purge') && apiError.status === 404) {
      notify({ title: 'Заметка больше не доступна', status: 'error' })
      await load(page.value)
    } else {
      notify({
        title: ACTION_ERRORS[kind],
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
  return kind === 'purge' ? purgeWarning(noteTitle(note)) : deleteWarning(noteTitle(note))
}

function confirmLabel(note: Note): string {
  return confirmedKind(note) === 'purge' ? 'Стереть навсегда' : 'Удалить'
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
  // Родитель монтирует панель через v-if, поэтому open=true уже при create.
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
    title="Архив заметок"
    @update:open="emit('update:open', $event)"
  >
    <div class="archive-panel">
      <div class="archive-panel__tabs" role="tablist" aria-label="Разделы архива">
        <button
          type="button"
          role="tab"
          class="archive-panel__tab"
          :class="{ 'archive-panel__tab--active': section === 'notRelevant' }"
          :aria-selected="section === 'notRelevant'"
          :disabled="loading || isBusy"
          @click="switchSection('notRelevant')"
        >
          Неактуальные
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
          Удалённые
        </button>
      </div>

      <div ref="listRef" class="archive-panel__list">
        <div v-if="loading" class="archive-panel__skeleton" aria-busy="true">
          <div v-for="row in 3" :key="row" class="archive-panel__skeleton-row" />
        </div>

        <div v-else-if="error" class="archive-panel__error">
          <p class="archive-panel__error-text">{{ error.message }}</p>
          <UiButton variant="ghost" size="sm" @click="load(page)">Повторить</UiButton>
        </div>

        <p v-else-if="items.length === 0" class="archive-panel__empty">
          {{ archiveEmptyMessage(section) }}
        </p>

        <article v-for="note in items" :key="note.id" class="archive-panel__item">
          <div class="archive-panel__item-main">
            <p class="archive-panel__item-title" :title="noteTitle(note)">{{ noteTitle(note) }}</p>
            <div class="archive-panel__item-meta">
              <span>Важность {{ note.importance }}</span>
              <span v-if="section === 'deleted' && formatDate(note.deletedAt)">
                удалена {{ formatDate(note.deletedAt) }}
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
                Отмена
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
                Вернуть
              </UiButton>
              <UiButton
                variant="ghost"
                size="sm"
                :disabled="loading || isBusy"
                @click="requestAction(note, 'soft-delete')"
              >
                Удалить
              </UiButton>
            </template>
            <template v-else>
              <UiButton size="sm" :disabled="loading || isBusy" @click="runAction(note, 'restore')">
                Восстановить
              </UiButton>
              <UiButton
                variant="ghost"
                size="sm"
                class="archive-panel__danger"
                :disabled="loading || isBusy"
                @click="requestAction(note, 'purge')"
              >
                Стереть навсегда
              </UiButton>
            </template>
          </div>
        </article>
      </div>

      <nav v-if="totalPages > 1" class="archive-panel__pagination" aria-label="Страницы архива">
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="loading || isBusy || page <= 1"
          @click="goToPage(page - 1)"
        >
          Назад
        </UiButton>
        <span class="archive-panel__pagination-info">
          Страница {{ page }} из {{ totalPages }} · всего {{ total }}
        </span>
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="loading || isBusy || page >= totalPages"
          @click="goToPage(page + 1)"
        >
          Вперёд
        </UiButton>
      </nav>
    </div>
  </UiSheet>
</template>
