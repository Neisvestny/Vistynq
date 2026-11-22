<script setup lang="ts">
/**
 * Панель редактора заметки (R28): загрузка NoteDetail, черновик манифеста
 * и тела, Tiptap, сохранение PATCH → PUT body → PUT visibility,
 * read-only режим и заглушка кнопки «Доступ» (R32).
 */
import { computed, ref, watch } from 'vue'
import type { Editor } from '@tiptap/vue-3'
import { accessApi } from '@/api/access'
import { attachmentsApi } from '@/api/attachments'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import { notesApi } from '@/api/notes'
import { tagsApi } from '@/api/tags'
import type { Attachment, NoteDetail, Tag } from '@/api/types'
import MarkdownEditor from '@/components/editor/MarkdownEditor.vue'
import ManifestForm from '@/components/note/ManifestForm.vue'
import NoteFilesSection, { type PendingUpload } from '@/components/note/NoteFilesSection.vue'
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
const attachments = ref<Attachment[]>([])
const pendingUploads = ref<PendingUpload[]>([])
const editorRef = ref<Editor | null>(null)
const imageInputRef = ref<HTMLInputElement | null>(null)
let requestSeq = 0
let confirmingClose = false

const IMAGE_MAX_BYTES = 5 * 1024 * 1024
const AUDIO_MAX_BYTES = 25 * 1024 * 1024
const TEXT_IMPORT_MAX_BYTES = 1024 * 1024
const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp']
const AUDIO_EXTS = ['mp3', 'ogg', 'wav']
const TEXT_EXTS = ['md', 'txt']

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
  attachments.value = [...(next.attachments ?? [])]
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
      attachments.value = []
      pendingUploads.value = []
      editorRef.value = null
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

function onEditorReady(editor: Editor): void {
  editorRef.value = editor
}

function extOf(name: string): string {
  const idx = name.lastIndexOf('.')
  return idx >= 0 ? name.slice(idx + 1).toLowerCase() : ''
}

function fileKind(file: File): 'image' | 'audio' | 'text' | 'unsupported' {
  const ext = extOf(file.name)
  const type = file.type.toLowerCase()
  const imageMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']
  const audioMimes = [
    'audio/mpeg',
    'audio/mp3',
    'audio/ogg',
    'audio/wav',
    'audio/x-wav',
    'audio/wave',
  ]
  const textMimes = ['text/plain', 'text/markdown', 'text/x-markdown']

  if (IMAGE_EXTS.includes(ext) || imageMimes.includes(type)) return 'image'
  if (AUDIO_EXTS.includes(ext) || audioMimes.includes(type)) return 'audio'
  if (TEXT_EXTS.includes(ext) || textMimes.includes(type)) return 'text'
  if (type.startsWith('image/')) return 'unsupported'
  if (type.startsWith('audio/')) return 'unsupported'
  return 'unsupported'
}

function validateUploadFile(file: File, kind: 'image' | 'audio'): string | null {
  if (kind === 'image' && file.size > IMAGE_MAX_BYTES)
    return 'Изображение должно быть не больше 5 МБ'
  if (kind === 'audio' && file.size > AUDIO_MAX_BYTES) return 'Аудио должно быть не больше 25 МБ'
  return null
}

function clampedPos(pos: number | null): number | null {
  if (typeof pos !== 'number') return null
  const editor = editorRef.value
  if (!editor) return null
  const max = editor.state.doc.content.size
  return Math.max(0, Math.min(pos, max))
}

function insertImageAt(pos: number | null, src: string, alt: string): void {
  const editor = editorRef.value
  if (!editor) return
  const chain = editor.chain().focus()
  const safePos = clampedPos(pos)
  const content = { type: 'image', attrs: { src, alt } }
  if (safePos !== null) chain.insertContentAt(safePos, content).run()
  else chain.insertContent(content).run()
}

function insertTextAt(pos: number | null, text: string): void {
  const editor = editorRef.value
  if (!editor) return
  const chain = editor.chain().focus()
  const safePos = clampedPos(pos)
  if (safePos !== null) chain.insertContentAt(safePos, text).run()
  else chain.insertContent(text).run()
}

async function uploadAttachmentFile(
  file: File,
  kind: 'image' | 'audio',
  pos: number | null
): Promise<void> {
  const noteId = detail.value?.id
  if (!noteId || readOnly.value) return

  const validationError = validateUploadFile(file, kind)
  if (validationError) {
    notify({ title: 'Файл не загружен', description: validationError, status: 'error' })
    return
  }

  const uploadId = `${Date.now()}-${Math.random().toString(36).slice(2)}`
  const pending: PendingUpload = { id: uploadId, file, kind, progress: 0, error: null }
  pendingUploads.value = [...pendingUploads.value, pending]

  try {
    const attachment = await attachmentsApi.upload(noteId, file, (p) => {
      pendingUploads.value = pendingUploads.value.map((u) =>
        u.id === uploadId ? { ...u, progress: p.percent } : u
      )
    })
    attachments.value = [...attachments.value, attachment]
    pendingUploads.value = pendingUploads.value.filter((u) => u.id !== uploadId)

    if (kind === 'image') {
      insertImageAt(pos, attachment.url, attachment.filename)
      notify({ title: 'Изображение добавлено', status: 'success' })
    } else {
      notify({ title: 'Аудио добавлено', status: 'success' })
    }
  } catch (error) {
    const message = error instanceof ApiError ? error.message : 'Не удалось загрузить файл'
    pendingUploads.value = pendingUploads.value.map((u) =>
      u.id === uploadId ? { ...u, error: message, progress: 0 } : u
    )
    notify({ title: 'Файл не загружен', description: message, status: 'error' })
  }
}

async function handleEditorFilesDrop(payload: {
  files: File[]
  pos: number | null
}): Promise<void> {
  if (readOnly.value || !detail.value) return

  for (const file of payload.files) {
    const kind = fileKind(file)
    if (kind === 'image' || kind === 'audio') {
      await uploadAttachmentFile(file, kind, payload.pos)
    } else if (kind === 'text') {
      if (file.size > TEXT_IMPORT_MAX_BYTES) {
        notify({
          title: 'Файл слишком большой',
          description: 'Файл .md/.txt должен быть не больше 1 МБ',
          status: 'error',
        })
        continue
      }
      try {
        const text = await file.text()
        insertTextAt(payload.pos, text)
        notify({ title: 'Текст вставлен из файла', status: 'success' })
      } catch {
        notify({ title: 'Не удалось прочитать файл', status: 'error' })
      }
    } else {
      notify({
        title: 'Неподдерживаемый файл',
        description: 'В редактор можно добавить картинку, аудио или вставить .md/.txt как текст',
        status: 'error',
      })
    }
  }
}

function onPickImage(): void {
  if (readOnly.value) return
  imageInputRef.value?.click()
}

async function onImageInputChange(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement | null
  const files = Array.from(input?.files ?? [])
  if (input) input.value = ''
  for (const file of files) {
    const kind = fileKind(file)
    if (kind === 'image') {
      await uploadAttachmentFile(file, 'image', null)
    } else {
      notify({ title: 'Можно выбрать только изображение', status: 'error' })
    }
  }
}

async function onDeleteAttachment(id: string): Promise<void> {
  const previous = attachments.value
  attachments.value = attachments.value.filter((a) => a.id !== id)
  try {
    await attachmentsApi.remove(id)
    notify({ title: 'Файл удалён', status: 'success' })
  } catch (error) {
    attachments.value = previous
    notify({
      title: 'Не удалось удалить файл',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  }
}

function dismissPendingUpload(id: string): void {
  pendingUploads.value = pendingUploads.value.filter((u) => u.id !== id)
}

async function onMediaError(attachment: Attachment): Promise<void> {
  try {
    const refreshed = await attachmentsApi.getUrl(attachment.id)
    attachments.value = attachments.value.map((a) =>
      a.id === attachment.id ? { ...a, url: refreshed.url } : a
    )
  } catch {
    // Тихо: медиа может быть недоступно из-за прав/удаления.
  }
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
          :can-upload-files="!readOnly"
          @ready="onEditorReady"
          @files-drop="handleEditorFilesDrop"
          @pick-image="onPickImage"
        />
        <input
          ref="imageInputRef"
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          class="note-editor__file-input"
          multiple
          @change="onImageInputChange"
        />
      </section>

      <NoteFilesSection
        :attachments="attachments"
        :pending-uploads="pendingUploads"
        :can-edit="!readOnly"
        @delete="onDeleteAttachment"
        @dismiss-upload="dismissPendingUpload"
        @media-error="onMediaError"
      />
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

.note-editor__file-input {
  display: none;
}
</style>
