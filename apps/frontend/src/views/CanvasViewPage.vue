<script setup lang="ts">
/**
 * CanvasView (docs/ui-ux.md §2) — страница канваса: загрузка/ошибки/шапка и
 * read-only рендер графа через CanvasBoard + useGraph (R25).
 */
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CanvasBoard from '@/components/canvas/CanvasBoard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useGraph } from '@/composables/useGraph'
import { useCanvasStore } from '@/stores/canvas'

const route = useRoute()
const router = useRouter()

const store = useCanvasStore()
const { flowNodes, flowEdges, visibleNodes, brokenEdgesCount } = useGraph()

/** Псевдонимы store для шаблона (v-if сузит тип, как с локальными ref). */
const graph = computed(() => store.canvas)
const loading = computed(() => store.loading)
const error = computed(() => store.error)

const roleLabel = computed(() => {
  switch (store.canvas?.access.myRole) {
    case 'owner':
      return 'Владелец'
    case 'editor':
      return 'Редактор'
    case 'viewer':
      return 'Зритель'
    default:
      return ''
  }
})

function loadGraph() {
  const id = route.params.id
  void store.load(typeof id === 'string' ? id : '')
}

onMounted(loadGraph)
// Переход между канвасами на том же маршруте — перезагрузка графа
watch(
  () => route.params.id,
  (id, prev) => {
    if (id !== prev) loadGraph()
  }
)
</script>

<template>
  <div class="canvas-view">
    <!-- Шапка канваса -->
    <header v-if="graph && !error" class="canvas-view__header">
      <div class="canvas-view__heading">
        <h1 class="canvas-view__title">{{ graph.title }}</h1>
        <UiBadge v-if="roleLabel">{{ roleLabel }}</UiBadge>
        <UiBadge v-if="graph.access.publicSlug" tone="accent">Публичный</UiBadge>
      </div>

      <div class="canvas-view__meta">
        <span v-if="brokenEdgesCount > 0" class="canvas-view__broken">
          {{ brokenEdgesCount }} связ. с удалёнными заметками
        </span>
        <span class="canvas-view__count">{{ visibleNodes.length }} зам.</span>
      </div>
    </header>

    <!-- Загрузка -->
    <div v-if="loading" class="canvas-view__board canvas-view__board--loading" aria-busy="true">
      <div class="canvas-view__skeleton" />
    </div>

    <!-- Ошибка -->
    <div v-else-if="error" class="canvas-view__error">
      <p class="canvas-view__error-title">
        {{
          error.status === 404 || error.status === 403
            ? 'Канвас недоступен'
            : 'Не удалось загрузить канвас'
        }}
      </p>
      <p class="canvas-view__error-text">{{ error.message }}</p>
      <div class="canvas-view__error-actions">
        <UiButton size="sm" @click="router.push({ name: 'dashboard' })">К списку канвасов</UiButton>
        <UiButton
          v-if="error.status !== 404 && error.status !== 403"
          variant="ghost"
          size="sm"
          @click="loadGraph"
        >
          Повторить
        </UiButton>
      </div>
    </div>

    <!-- Граф -->
    <div v-else class="canvas-view__board">
      <CanvasBoard :nodes="flowNodes" :edges="flowEdges">
        <!-- Пустой канвас -->
        <div v-if="visibleNodes.length === 0" class="canvas-view__empty">
          <p class="canvas-view__empty-title">Здесь пока нет заметок</p>
          <p class="canvas-view__empty-text">
            Чтение графа работает; создание и редактирование появятся в следующих итерациях
            (R26–R27).
          </p>
        </div>
      </CanvasBoard>
    </div>
  </div>
</template>
