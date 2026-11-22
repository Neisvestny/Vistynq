<script setup lang="ts">
/**
 * Note files section: image/audio attachments, upload progress, player and deletion.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import type { Attachment } from '@/api/types'

export interface PendingUpload {
  id: string
  file: File
  kind: 'image' | 'audio' | 'unknown'
  progress: number
  error: string | null
}

const props = withDefaults(
  defineProps<{
    attachments: Attachment[]
    pendingUploads?: PendingUpload[]
    canEdit?: boolean
  }>(),
  { pendingUploads: () => [], canEdit: false }
)

const emit = defineEmits<{
  delete: [id: string]
  dismissUpload: [id: string]
  mediaError: [attachment: Attachment]
}>()

const { t } = useI18n()

const visibleAttachments = computed(() =>
  props.attachments.filter((a) => a.kind === 'image' || a.kind === 'audio')
)

function formatBytes(size: number): string {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function isImage(attachment: Attachment): boolean {
  return attachment.kind === 'image' || attachment.mime.startsWith('image/')
}

function isAudio(attachment: Attachment): boolean {
  return attachment.kind === 'audio' || attachment.mime.startsWith('audio/')
}
</script>

<template>
  <section class="note-files" :aria-label="t('note.filesAria')">
    <div class="note-files__header">
      <h3 class="note-files__title">{{ t('note.filesTitle') }}</h3>
      <span v-if="visibleAttachments.length > 0" class="note-files__count">
        {{ visibleAttachments.length }}
      </span>
    </div>

    <p
      v-if="visibleAttachments.length === 0 && pendingUploads.length === 0"
      class="note-files__empty"
    >
      {{ t('note.filesEmpty') }}
    </p>

    <ul v-if="visibleAttachments.length > 0" class="note-files__list">
      <li v-for="attachment in visibleAttachments" :key="attachment.id" class="note-files__item">
        <div v-if="isImage(attachment)" class="note-files__preview">
          <a :href="attachment.url" target="_blank" rel="noopener noreferrer">
            <img
              :src="attachment.url"
              :alt="attachment.filename"
              class="note-files__image"
              loading="lazy"
              @error="emit('mediaError', attachment)"
            />
          </a>
        </div>

        <div class="note-files__body">
          <div class="note-files__meta">
            <a
              :href="attachment.url"
              target="_blank"
              rel="noopener noreferrer"
              class="note-files__name"
              :title="attachment.filename"
            >
              {{ attachment.filename }}
            </a>
            <span class="note-files__size">{{ formatBytes(attachment.sizeBytes) }}</span>
          </div>

          <audio
            v-if="isAudio(attachment)"
            class="note-files__audio"
            controls
            preload="none"
            :src="attachment.url"
            @error="emit('mediaError', attachment)"
          />

          <div v-if="canEdit" class="note-files__actions">
            <UiButton
              type="button"
              variant="ghost"
              size="sm"
              :aria-label="t('note.deleteFileAria', { name: attachment.filename })"
              @click="emit('delete', attachment.id)"
            >
              {{ t('common.delete') }}
            </UiButton>
          </div>
        </div>
      </li>
    </ul>

    <ul v-if="pendingUploads.length > 0" class="note-files__uploads" aria-live="polite">
      <li v-for="upload in pendingUploads" :key="upload.id" class="note-files__upload">
        <div class="note-files__upload-top">
          <span class="note-files__name" :title="upload.file.name">{{ upload.file.name }}</span>
          <span class="note-files__size">{{ formatBytes(upload.file.size) }}</span>
        </div>

        <div
          v-if="!upload.error"
          class="note-files__progress"
          role="progressbar"
          :aria-valuenow="upload.progress"
          aria-valuemin="0"
          aria-valuemax="100"
        >
          <div class="note-files__progress-bar" :style="{ width: `${upload.progress}%` }" />
        </div>
        <p v-if="!upload.error" class="note-files__progress-text">
          {{ t('note.uploadingProgress', { progress: upload.progress }) }}
        </p>

        <div v-else class="note-files__upload-error">
          <p class="note-files__error-text">{{ upload.error }}</p>
          <UiButton
            type="button"
            variant="ghost"
            size="sm"
            @click="emit('dismissUpload', upload.id)"
          >
            {{ t('note.hide') }}
          </UiButton>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.note-files {
  padding: 0 1rem 1rem;
}

.note-files__header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.note-files__title {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.note-files__count {
  padding: 0.05rem 0.45rem;
  border-radius: 999px;
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.05));
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.note-files__empty {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.note-files__list,
.note-files__uploads {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

.note-files__item,
.note-files__upload {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  overflow: hidden;
}

.note-files__preview {
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.03));
}

.note-files__image {
  display: block;
  width: 100%;
  max-height: 220px;
  object-fit: contain;
}

.note-files__body,
.note-files__upload {
  padding: 0.65rem 0.75rem;
}

.note-files__meta,
.note-files__upload-top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.note-files__name {
  min-width: 0;
  overflow: hidden;
  color: var(--color-text);
  font-size: 0.875rem;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-decoration: none;
}

a.note-files__name:hover {
  color: var(--color-accent);
  text-decoration: underline;
}

.note-files__size {
  flex: 0 0 auto;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.note-files__audio {
  width: 100%;
  margin-top: 0.5rem;
}

.note-files__actions {
  margin-top: 0.5rem;
}

.note-files__progress {
  height: 6px;
  margin-top: 0.5rem;
  overflow: hidden;
  border-radius: 999px;
  background: var(--color-border);
}

.note-files__progress-bar {
  height: 100%;
  background: var(--color-accent);
  transition: width 0.15s ease;
}

.note-files__progress-text {
  margin: 0.35rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.note-files__upload-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.note-files__error-text {
  margin: 0;
  color: var(--color-danger, #b42318);
  font-size: 0.8rem;
}
</style>
