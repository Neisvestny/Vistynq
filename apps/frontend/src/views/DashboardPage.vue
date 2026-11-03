<script setup lang="ts">
/**
 * Dashboard (docs/ui-ux.md §1) — список канвасов из GET /canvases,
 * создание канваса, секция приглашений-заглушка (инвайты — R31).
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiDialog from '@/components/ui/UiDialog.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { canvasApi } from '@/api/canvas'
import { applyApiError } from '@/api/errors'
import { notify } from '@/api/notify'
import type { Canvas } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const canvases = ref<Canvas[]>([])
const loading = ref(true)
const loadFailed = ref(false)

const creating = ref(false)
const createErrors = ref<Record<string, string>>({})
const newTitle = ref('')

async function loadCanvases() {
  loading.value = true
  loadFailed.value = false
  try {
    canvases.value = (await canvasApi.list()).items
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

async function onCreateCanvas() {
  if (creating.value) return

  createErrors.value = {}
  const title = newTitle.value.trim()
  if (!title) {
    createErrors.value.title = 'Введите название канваса'
    return
  }

  creating.value = true
  try {
    const canvas = await canvasApi.create({ title })
    await router.push({ name: 'canvas', params: { id: canvas.id } })
  } catch (error) {
    applyApiError(error, createErrors.value)
  } finally {
    creating.value = false
  }
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function roleLabel(canvas: Canvas): string {
  switch (canvas.access.myRole) {
    case 'owner':
      return 'Владелец'
    case 'editor':
      return 'Редактор'
    case 'viewer':
      return 'Зритель'
    default:
      return ''
  }
}

onMounted(async () => {
  // guard уже восстановил сессию, но после прямого F5 /auth/me может не быть
  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch {
      notify({ title: 'Не удалось загрузить профиль', status: 'error' })
    }
  }
  await loadCanvases()
})
</script>

<template>
  <div class="dashboard">
    <header class="dashboard__header">
      <div>
        <h1 class="dashboard__title">Привет{{ auth.user ? `, ${auth.user.displayName}` : '' }}!</h1>
        <p class="dashboard__subtitle">Ваши канвасы</p>
      </div>

      <UiDialog title="Новый канвас" description="Название можно изменить позже">
        <template #trigger>
          <UiButton>+ Создать канвас</UiButton>
        </template>

        <form class="dashboard__create" novalidate @submit.prevent="onCreateCanvas">
          <UiInput
            id="new-canvas-title"
            v-model="newTitle"
            placeholder="Например: Исследования"
            :disabled="creating"
          >
            Название
          </UiInput>
          <p v-if="createErrors.title" class="dashboard__error">{{ createErrors.title }}</p>

          <UiButton type="submit" :disabled="creating">
            {{ creating ? 'Создаём…' : 'Создать' }}
          </UiButton>
        </form>
      </UiDialog>
    </header>

    <!-- Загрузка -->
    <div v-if="loading" class="dashboard__grid" aria-busy="true">
      <UiCard v-for="n in 3" :key="n" class="dashboard__skeleton" />
    </div>

    <!-- Ошибка загрузки -->
    <UiCard v-else-if="loadFailed">
      <p class="dashboard__hint">Не удалось загрузить канвасы — проверьте соединение.</p>
      <UiButton variant="ghost" size="sm" @click="loadCanvases">Повторить</UiButton>
    </UiCard>

    <!-- Пусто -->
    <UiCard v-else-if="canvases.length === 0">
      <p class="dashboard__hint">
        Канвасов пока нет. Создайте первый — это пространство с заметками и связями.
      </p>
    </UiCard>

    <!-- Список -->
    <div v-else class="dashboard__grid">
      <UiCard
        v-for="canvas in canvases"
        :key="canvas.id"
        class="dashboard__card"
        @click="router.push({ name: 'canvas', params: { id: canvas.id } })"
      >
        <div class="dashboard__card-top">
          <h2 class="dashboard__card-title">{{ canvas.title }}</h2>
          <UiBadge v-if="canvas.access.publicSlug" tone="accent">Публичный</UiBadge>
        </div>

        <div class="dashboard__card-meta">
          <UiBadge v-if="roleLabel(canvas)">{{ roleLabel(canvas) }}</UiBadge>
          <span class="dashboard__meta-text"> {{ canvas.noteCount ?? 0 }} зам. </span>
          <span class="dashboard__meta-text">{{ formatDate(canvas.createdAt) }}</span>
        </div>
      </UiCard>
    </div>

    <section class="dashboard__invites">
      <h2 class="dashboard__invites-title">Приглашения</h2>
      <p class="dashboard__hint">
        Входящие инвайты с кнопками «Принять»/«Отклонить» появятся здесь (R31).
      </p>
    </section>
  </div>
</template>
