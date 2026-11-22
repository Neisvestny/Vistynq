<script setup lang="ts">
/**
 * Панель редактора заметки (R28): загрузка NoteDetail, черновик манифеста
 * и тела, Tiptap, сохранение PATCH → PUT body → PUT visibility,
 * read-only режим и заглушка кнопки «Доступ» (R32).
 */
import { computed, ref, watch } from 'vue'
import { accessApi } from '@/api/access'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import { notesApi } from '@/api/notes'
import { tagsApi } from '@/api/tags'
import type { NoteDetail, Tag } from '@/api/types'
import MarkdownEditor from '@/components/editor/MarkdownEditor.vue'
import ManifestForm from '@/components/note/ManifestForm.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import {
  buildSavePlan,
  draftFromDetail,
  isDraftDirty,
  validateManifest,
  type NoteDraft,
  type SavePlan,
} from '@/composables/useNoteDraft'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'
import { useUiStore, type NoteEditorSide } from '@/stores/ui'

const props = withDefaults(
  defineProps<{
    open: boolean
    noteId: string | null
    side?: NoteEditorSide
  }>(),
  { side: undefined }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  saved: [detail: NoteDetail, plan: SavePlan]
  'open-access': [noteId: string]
}>()

const canvasStore = useCanvasStore()
const notesStore = useNotesStore()
const uiStore = useUiStore()

const detail = ref<NoteDetail | null>(null)
const draft = ref<NoteDraft>(emptyDraft())
const baseline = ref<NoteDraft>(emptyDraft())
const loading = ref(false)
const saving = ref(false)
const loadError = ref<ApiError | null>(null)
let requestSeq = 0
let confirmingClose = false

const side = computed<NoteEditorSide>(() => props.side ?? uiStore.noteEditorSide)
const dirty = computed(() => isDraftDirty(baseline.value, draft.value))

const guard = useAccessGuard(
  computed(() =>
    detail.value ? { authorId: detail.value.authorId, myRole: detail.value.myRole } : null
  )
)
const canEdit = computed(() => guard.canEditNote(detail.value))
const canManageAccess = computed(() => guard.canManageNoteAccess(detail.value))
const readOnly = computed(() => !canEdit.value || Boolean(detail.value?.deletedAt))
const canCreateTags = computed(() => guard.canEditCanvas.value)
const graphTags = computed<Tag[]>(() => canvasStore.tags)
const noteCandidates = computed(() =>
  notesStore.nodes.map((node) => ({ id: node.id, title: node.title }))
)

function emptyDraft(): NoteDraft {
  return {
    manifest: {
      title: '',
      importance: 3,
      isRelevant: true,
      tagIds: [],
      visibility: 'public',
    },
    bodyMd: '',
  }
}

function cloneDraft(value: NoteDraft): NoteDraft {
  return JSON.parse(JSON.stringify(value)) as NoteDraft
}

function applyDetail(next: NoteDetail): void {
  detail.value = next
  const initialized = draftFromDetail(next)
  baseline.value = initialized
  draft.value = cloneDraft(initialized)
}

async function loadNote(noteId: string): Promise<void> {
  const current = ++requestSeq
  loading.value = true
  loadError.value = null
  try {
    const loaded = await notesApi.get(noteId)
    if (current !== requestSeq) return
    applyDetail(loaded)
  } catch (error) {
    if (current !== requestSeq) return
    loadError.value = error instanceof ApiError ? error : ApiError.network()
  } finally {
    if (current === requestSeq) loading.value = false
  }
}

watch(
  [() => props.open, () => props.noteId],
  ([open, noteId]) => {
    if (!open || !noteId) {
      detail.value = null
      loadError.value = null
      return
    }
    void loadNote(noteId)
  },
  { immediate: true }
)

async function createTag(name: string): Promise<void> {
  const canvasId = canvasStore.canvas?.id
  if (!canvasId || !canCreateTags.value) return
  try {
    const tag = await tagsApi.create(canvasId, { name })
    canvasStore.tags = [...canvasStore.tags, tag].sort((a, b) => a.name.localeCompare(b.name, 'ru'))
    draft.value.manifest = {
      ...draft.value.manifest,
      tagIds: [...draft.value.manifest.tagIds, tag.id],
    }
    notify({ title: 'Тег создан', status: 'success' })
  } catch (error) {
    notify({
      title: 'Не удалось создать тег',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  }
}

async function save(): Promise<void> {
  const note = detail.value
  if (!note || saving.value || !dirty.value || readOnly.value) return

  const errors = validateManifest(draft.value.manifest)
  if (errors.length > 0) {
    notify({ title: errors[0] ?? 'Проверьте поля формы', status: 'error' })
    return
  }

  const plan = buildSavePlan(baseline.value, draft.value)
  saving.value = true
  try {
    let last: NoteDetail | undefined
    if (plan.manifestPatch) {
      last = await notesApi.updateManifest(note.id, plan.manifestPatch)
    }
    if (plan.bodyMd !== undefined) {
      last = await notesApi.updateBody(note.id, { bodyMd: plan.bodyMd })
    }
    if (plan.visibility !== undefined) {
      const result = await accessApi.setNoteVisibility(note.id, plan.visibility)
      if (last) last = { ...last, visibility: result.visibility }
    }

    const saved = last ?? (await notesApi.get(note.id))
    applyDetail(saved)
    emit('saved', saved, plan)
    notify({ title: 'Заметка сохранена', status: 'success' })
  } catch (error) {
    notify({
      title: 'Не удалось сохранить заметку',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    saving.value = false
  }
}

function requestClose(): void {
  if (saving.value || confirmingClose) return
  if (!dirty.value) {
    emit('update:open', false)
    return
  }
  confirmingClose = true
  const confirmed = window.confirm('Закрыть редактор без сохранения изменений?')
  confirmingClose = false
  if (confirmed) emit('update:open', false)
}

function onSheetOpenChange(value: boolean): void {
  if (value) {
    emit('update:open', true)
    return
  }
  requestClose()
}

function onOpenAccess(): void {
  if (detail.value) emit('open-access', detail.value.id)
}
</script>

<template>
  <UiSheet
    :open="open && noteId !== null"
    :side="side"
    :wide="true"
    :prevent-close="dirty"
    @update:open="onSheetOpenChange"
    @close-request="requestClose"
  >
    <template #title>
      <span class="note-editor__heading">
        <span class="note-editor__title" :title="detail?.title || 'Заметка'">
          {{ detail?.title || 'Заметка' }}
        </span>
        <UiBadge v-if="readOnly && detail">Только чтение</UiBadge>
        <button
          type="button"
          class="note-editor__side-toggle"
          :title="side === 'right' ? 'Переместить панель слева' : 'Переместить панель справа'"
          :aria-label="side === 'right' ? 'Переместить панель слева' : 'Переместить панель справа'"
          @click="uiStore.toggleNoteEditorSide()"
        >
          {{ side === 'right' ? '⇤' : '⇥' }}
        </button>
      </span>
    </template>

    <div v-if="loading" class="note-editor__state" aria-busy="true">Загрузка заметки…</div>

    <div v-else-if="loadError" class="note-editor__state">
      <p>{{ loadError.status === 404 ? 'Заметка недоступна' : 'Не удалось загрузить заметку' }}</p>
      <p class="note-editor__state-detail">{{ loadError.message }}</p>
      <UiButton
        v-if="loadError.status !== 404"
        variant="ghost"
        size="sm"
        type="button"
        @click="noteId && loadNote(noteId)"
      >
        Повторить
      </UiButton>
    </div>

    <template v-else-if="detail">
      <ManifestForm
        :model-value="draft.manifest"
        :tags="graphTags"
        :can-edit="canEdit"
        :can-manage-access="canManageAccess && !readOnly"
        :can-create-tags="canCreateTags"
        :author-id="detail.authorId"
        @update:model-value="draft.manifest = $event"
        @create-tag="createTag"
        @open-access="onOpenAccess"
      />

      <section class="note-editor__body">
        <h3 class="note-editor__section-title">Содержимое</h3>
        <MarkdownEditor
          v-model="draft.bodyMd"
          :editable="!readOnly"
          :note-candidates="noteCandidates"
          :current-note-id="detail.id"
        />
      </section>
    </template>

    <template #footer>
      <div v-if="detail && !readOnly" class="note-editor__footer">
        <UiButton type="button" variant="primary" :disabled="!dirty || saving" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </UiButton>
        <UiButton type="button" variant="ghost" :disabled="saving" @click="requestClose">
          Закрыть
        </UiButton>
        <span v-if="dirty" class="note-editor__dirty">Есть несохранённые изменения</span>
      </div>
      <div v-else-if="detail" class="note-editor__footer">
        <UiButton type="button" variant="ghost" @click="requestClose">Закрыть</UiButton>
      </div>
    </template>
  </UiSheet>
</template>

<style scoped>
.note-editor__heading {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  min-width: 0;
}

.note-editor__title {
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.note-editor__heading :deep(.ui-badge) {
  flex: 0 0 auto;
}

.note-editor__side-toggle {
  flex: 0 0 auto;
  margin-left: auto;
  padding: 0.1rem 0.4rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  cursor: pointer;
}

.note-editor__state {
  padding: 1rem;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.note-editor__state-detail {
  margin: 0.25rem 0 0.75rem;
  font-size: 0.85rem;
}

.note-editor__body {
  padding: 0 1rem 1rem;
}

.note-editor__section-title {
  margin: 0 0 0.5rem;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.note-editor__footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.note-editor__dirty {
  color: var(--color-text-muted);
  font-size: 0.8rem;
}
</style>
