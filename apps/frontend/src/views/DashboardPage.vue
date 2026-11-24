<script setup lang="ts">
/**
 * Dashboard (docs/ui-ux.md §1) — spisok kanvasov iz GET /canvases (postranichno),
 * sozdanie kanvasa, sektsiya priglasheniy (ostavlena po resheniyu polzovatelya).
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Trash2 } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiDialog from '@/components/ui/UiDialog.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { applyApiError } from '@/api/errors'
import { notify } from '@/api/notify'
import type { Canvas } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useCanvasesStore } from '@/stores/canvases'

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const store = useCanvasesStore()

/** Lokalnoe sostoyanie formy dialoga (ne globalnoe). */
const createErrors = ref<Record<string, string>>({})
const newTitle = ref('')
const createOpen = ref(false)
const confirmingDeleteId = ref<string | null>(null)

const greeting = computed(() => {
  const suffix = auth.user ? t('dashboard.greetingSuffix', { name: auth.user.displayName }) : ''
  return t('dashboard.greeting', { suffix })
})

async function onCreateCanvas() {
  if (store.creating) return

  createErrors.value = {}
  const title = newTitle.value.trim()
  if (!title) {
    createErrors.value.title = t('dashboard.canvasTitleRequired')
    return
  }

  try {
    const canvas = await store.create(title)
    createOpen.value = false
    newTitle.value = ''
    await router.push({ name: 'canvas', params: { id: canvas.id } })
  } catch (error) {
    applyApiError(error, createErrors.value)
  }
}

async function onDeleteCanvas(canvas: Canvas) {
  if (store.deletingId) return
  try {
    await store.remove(canvas.id)
    confirmingDeleteId.value = null
    notify({ title: t('dashboard.canvasDeleted', { title: canvas.title }), status: 'success' })
  } catch (error) {
    notify({ title: applyApiError(error, {}), status: 'error' })
  }
}

function formatDate(iso: string): string {
  const intlLocale = locale.value === 'en' ? 'en-US' : 'ru-RU'
  return new Date(iso).toLocaleDateString(intlLocale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function roleTone(canvas: Canvas): 'owner' | 'editor' | 'viewer' | undefined {
  if (canvas.access.myRole === 'owner') return 'owner'
  if (canvas.access.myRole === 'editor') return 'editor'
  if (canvas.access.myRole === 'viewer') return 'viewer'
  return undefined
}

function roleLabel(canvas: Canvas): string {
  switch (canvas.access.myRole) {
    case 'owner':
      return t('dashboard.roleOwner')
    case 'editor':
      return t('dashboard.roleEditor')
    case 'viewer':
      return t('dashboard.roleViewer')
    case 'none':
      return t('dashboard.roleNotesAccess')
    default:
      return ''
  }
}

async function goToPage(nextPage: number) {
  if (store.loading) return
  const target = Math.min(Math.max(1, nextPage), store.totalPages)
  if (target === store.page) return
  await store.load(target)
}

onMounted(async () => {
  // guard uzhe vosstanovil sessiyu, no posle pryamogo F5 /auth/me mozhet ne byt
  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch {
      notify({ title: t('dashboard.profileLoadError'), status: 'error' })
    }
  }
  await store.load()
})
</script>

<template>
  <div class="dashboard">
    <header class="dashboard__header">
      <div>
        <h1 class="dashboard__title">{{ greeting }}</h1>
        <p class="dashboard__subtitle">{{ t('dashboard.subtitle') }}</p>
      </div>

      <UiDialog
        v-model:open="createOpen"
        :title="t('dashboard.createDialogTitle')"
        :description="t('dashboard.createDialogDescription')"
        :busy="store.creating"
      >
        <template #trigger>
          <UiButton>+ {{ t('dashboard.createCanvas') }}</UiButton>
        </template>

        <form class="dashboard__create" novalidate @submit.prevent="onCreateCanvas">
          <UiInput
            id="new-canvas-title"
            v-model="newTitle"
            :placeholder="t('dashboard.titlePlaceholder')"
            :disabled="store.creating"
            :error="createErrors.title"
          >
            {{ t('dashboard.titleLabel') }}
          </UiInput>

          <UiButton type="submit" :disabled="store.creating">
            {{ store.creating ? t('common.creating') : t('common.create') }}
          </UiButton>
        </form>
      </UiDialog>
    </header>

    <!-- Loading -->
    <div
      v-if="store.loading"
      class="dashboard__grid"
      aria-busy="true"
      role="status"
      :aria-label="t('common.loading')"
    >
      <UiCard v-for="n in 3" :key="n" class="dashboard__skeleton" />
    </div>

    <!-- Error zagruzki -->
    <UiCard v-else-if="store.loadFailed" role="alert">
      <p class="dashboard__hint">{{ t('dashboard.loadErrorHint') }}</p>
      <UiButton variant="ghost" size="sm" @click="store.load(store.page || 1)">
        {{ t('common.retry') }}
      </UiButton>
    </UiCard>

    <!-- Pusto -->
    <UiCard v-else-if="store.items.length === 0">
      <h2 class="dashboard__empty-title">{{ t('dashboard.emptyTitle') }}</h2>
      <p class="dashboard__hint">{{ t('dashboard.emptyHint') }}</p>
    </UiCard>

    <!-- Spisok -->
    <template v-else>
      <ul class="dashboard__grid">
        <li v-for="canvas in store.items" :key="canvas.id" class="ui-card dashboard__card">
          <RouterLink class="dashboard__card-link" :to="{ name: 'canvas', params: { id: canvas.id } }">
            <div class="dashboard__card-top">
              <h2 class="dashboard__card-title">{{ canvas.title }}</h2>
              <UiBadge v-if="canvas.access.level === 'public'" tone="public">
                {{ t('dashboard.public') }}
              </UiBadge>
            </div>

            <div class="dashboard__card-meta">
              <UiBadge v-if="roleLabel(canvas)" :tone="roleTone(canvas)">
                {{ roleLabel(canvas) }}
              </UiBadge>
              <span class="dashboard__meta-text">
                {{ t('dashboard.notesShort', { count: canvas.noteCount ?? 0 }) }}
              </span>
              <span class="dashboard__meta-text">
                {{ formatDate(canvas.updatedAt ?? canvas.createdAt) }}
              </span>
            </div>
          </RouterLink>

          <div v-if="canvas.access.myRole === 'owner'" class="dashboard__card-actions">
            <template v-if="confirmingDeleteId === canvas.id">
              <p class="dashboard__delete-hint" role="group">
                {{ t('dashboard.deleteConfirm', { title: canvas.title }) }}
              </p>
              <UiButton
                variant="danger"
                size="sm"
                :disabled="store.deletingId === canvas.id"
                @click="onDeleteCanvas(canvas)"
              >
                {{ store.deletingId === canvas.id ? t('common.deleting') : t('dashboard.deleteCanvas') }}
              </UiButton>
              <UiButton
                variant="ghost"
                size="sm"
                :disabled="store.deletingId === canvas.id"
                @click="confirmingDeleteId = null"
              >
                {{ t('common.cancel') }}
              </UiButton>
            </template>
            <UiButton
              v-else
              variant="ghost"
              size="sm"
              @click="confirmingDeleteId = canvas.id"
            >
              <Trash2 :size="16" aria-hidden="true" />
              <span class="sr-only">{{ t('dashboard.deleteCanvas') }}</span>
            </UiButton>
          </div>
        </li>
      </ul>

      <nav
        v-if="store.totalPages > 1"
        class="dashboard__pagination"
        :aria-label="t('dashboard.paginationLabel')"
      >
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="store.loading || store.page <= 1"
          @click="goToPage(store.page - 1)"
        >
          {{ t('common.back') }}
        </UiButton>
        <span class="dashboard__pagination-info">
          <span aria-current="page">{{ t('dashboard.pageInfo', { page: store.page, totalPages: store.totalPages }) }}</span>
          <span class="dashboard__pagination-total">
            {{ t('dashboard.totalInfo', { total: store.total }) }}
          </span>
        </span>
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="store.loading || store.page >= store.totalPages"
          @click="goToPage(store.page + 1)"
        >
          {{ t('common.forward') }}
        </UiButton>
      </nav>
    </template>

    <section class="dashboard__invites" aria-labelledby="dashboard-invites-title">
      <h2 id="dashboard-invites-title" class="dashboard__invites-title">
        {{ t('dashboard.invitesTitle') }}
      </h2>
      <p class="dashboard__hint">{{ t('dashboard.invitesHint') }}</p>
    </section>
  </div>
</template>
