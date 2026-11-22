<script setup lang="ts">
/** Публичная (анонимная) заметка — read-only рендер (R32). */
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/http'
import { sharedApi } from '@/api/shared'
import type { SharedNoteResponse } from '@/api/types'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import { sanitizeHtml } from '@/lib/sanitize'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref<ApiError | null>(null)
const data = ref<SharedNoteResponse | null>(null)
const mediaErrors = ref<Record<string, boolean>>({})

async function load(): Promise<void> {
  const slug = String(route.params.slug ?? '')
  const noteId = String(route.params.noteId ?? '')
  if (!slug || !noteId) {
    error.value = new ApiError(404, 'NOT_FOUND', 'Заметка не найдена')
    loading.value = false
    return
  }

  loading.value = true
  error.value = null
  try {
    data.value = await sharedApi.note(slug, noteId)
  } catch (e) {
    error.value = e instanceof ApiError ? e : ApiError.network()
  } finally {
    loading.value = false
  }
}

function backToCanvas(): void {
  router.push({ name: 'shared-canvas', params: { slug: route.params.slug } })
}

function onMediaError(id: string): void {
  mediaErrors.value = { ...mediaErrors.value, [id]: true }
}

function formatBytes(size: number): string {
  if (size < 1024) return `${size} Б`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`
  return `${(size / (1024 * 1024)).toFixed(1)} МБ`
}

onMounted(load)
watch(() => [route.params.slug, route.params.noteId], load)
</script>

<template>
  <div>
    <div class="shared-banner">Публичная версия — только чтение</div>

    <div class="shared-note-page">
      <div v-if="loading" class="shared-note-page__state" aria-busy="true">Загрузка…</div>

      <div v-else-if="error" class="shared-note-page__state">
        <UiCard class="shared-note-page__card">
          <h1 class="shared-note-page__title">
            {{ error.status === 404 ? 'Заметка недоступна' : 'Не удалось загрузить заметку' }}
          </h1>
          <p class="shared-note-page__muted">{{ error.message }}</p>
          <div class="shared-note-page__actions">
            <UiButton size="sm" @click="backToCanvas">К канвасу</UiButton>
            <UiButton v-if="error.status !== 404" variant="ghost" size="sm" @click="load">
              Повторить
            </UiButton>
          </div>
        </UiCard>
      </div>

      <article v-else-if="data" class="shared-note-page__article">
        <div class="shared-note-page__topbar">
          <UiButton variant="ghost" size="sm" @click="backToCanvas">← К канвасу</UiButton>
        </div>

        <UiCard class="shared-note-page__card">
          <header class="shared-note-page__header">
            <h1 class="shared-note-page__title">{{ data.note.title }}</h1>
            <div class="shared-note-page__meta">
              <span>Важность: {{ data.note.importance }}</span>
              <span v-if="data.note.isRelevant === false">Неактуальна</span>
            </div>
          </header>

          <!-- eslint-disable-next-line vue/no-v-html -- sanitized server-rendered HTML -->
          <div class="shared-note-page__body" v-html="sanitizeHtml(data.bodyHtml)" />

          <section v-if="data.note.attachments?.length" class="shared-note-page__attachments">
            <h2 class="shared-note-page__section-title">Вложения</h2>
            <ul class="shared-note-page__files">
              <li
                v-for="file in data.note.attachments"
                :key="file.id"
                class="shared-note-page__file"
              >
                <div class="shared-note-page__file-meta">
                  <span class="shared-note-page__file-name">{{ file.filename }}</span>
                  <span class="shared-note-page__muted">{{ formatBytes(file.sizeBytes) }}</span>
                </div>

                <div v-if="!mediaErrors[file.id]">
                  <img
                    v-if="file.kind === 'image'"
                    :src="file.url"
                    :alt="file.filename"
                    class="shared-note-page__image"
                    loading="lazy"
                    @error="onMediaError(file.id)"
                  />
                  <audio
                    v-else-if="file.kind === 'audio'"
                    class="shared-note-page__audio"
                    controls
                    preload="none"
                    :src="file.url"
                    @error="onMediaError(file.id)"
                  />
                  <a v-else :href="file.url" target="_blank" rel="noopener noreferrer"
                    >Открыть файл</a
                  >
                </div>
                <p v-else class="shared-note-page__muted">Файл временно недоступен.</p>
              </li>
            </ul>
          </section>
        </UiCard>
      </article>
    </div>
  </div>
</template>

<style scoped>
.shared-note-page {
  max-width: 900px;
  margin: 0 auto;
  padding: 1rem;
}
.shared-note-page__state {
  padding: 2rem 0;
}
.shared-note-page__topbar {
  margin-bottom: 0.75rem;
}
.shared-note-page__card {
  display: grid;
  gap: 1rem;
}
.shared-note-page__header {
  display: grid;
  gap: 0.35rem;
}
.shared-note-page__title {
  margin: 0;
  font-size: clamp(1.5rem, 2vw, 2rem);
  line-height: 1.2;
}
.shared-note-page__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
.shared-note-page__muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
.shared-note-page__actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
.shared-note-page__body {
  line-height: 1.7;
  overflow-wrap: anywhere;
}
.shared-note-page__body :deep(p),
.shared-note-page__body :deep(ul),
.shared-note-page__body :deep(ol),
.shared-note-page__body :deep(blockquote),
.shared-note-page__body :deep(pre),
.shared-note-page__body :deep(h1),
.shared-note-page__body :deep(h2),
.shared-note-page__body :deep(h3) {
  margin: 0 0 0.85rem;
}
.shared-note-page__body :deep(pre) {
  overflow: auto;
  padding: 0.75rem;
  border-radius: 8px;
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.04));
}
.shared-note-page__body :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 8px;
}
.shared-note-page__body :deep(a) {
  color: var(--color-accent);
  text-decoration: underline;
}
.shared-note-page__attachments {
  border-top: 1px solid var(--color-border);
  padding-top: 1rem;
}
.shared-note-page__section-title {
  margin: 0 0 0.75rem;
  font-size: 1rem;
}
.shared-note-page__files {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}
.shared-note-page__file {
  display: grid;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.shared-note-page__file-meta {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}
.shared-note-page__file-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.shared-note-page__image {
  display: block;
  max-width: 100%;
  max-height: 420px;
  object-fit: contain;
  border-radius: 8px;
}
.shared-note-page__audio {
  width: 100%;
}
</style>
