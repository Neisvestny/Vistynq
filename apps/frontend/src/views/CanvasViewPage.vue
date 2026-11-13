<script setup lang="ts">
/**
 * CanvasView (docs/ui-ux.md §2) — read-only рендер графа из
 * GET /canvases/{id}/graph (R14). Ноды/рёбра маппятся в Vue Flow:
 * интерактив (drag/resize/контекст-меню/фильтры) — R25–R27.
 */
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MarkerType, VueFlow } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import NoteNode from '@/components/canvas/NoteNode.vue'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

const route = useRoute()
const router = useRouter()

const store = useCanvasStore()
const notes = useNotesStore()

/** Псевдонимы store для шаблона (v-if сузит тип, как с локальными ref). */
const graph = computed(() => store.canvas)
const loading = computed(() => store.loading)
const error = computed(() => store.error)

const tagColors = computed<Record<string, string>>(() =>
  Object.fromEntries(store.tags.map((tag) => [tag.name, tag.color]))
)

const visibleNodes = computed(() => notes.visibleNodes)
const visibleNodeIds = computed(() => notes.visibleNodeIds)

/** Рёбра, ведущие в удалённые заметки (state=removed, to=null) — линии нет, считаем для заголовка. */
const brokenEdges = computed(() => store.edges.filter((edge) => edge.state === 'removed').length)

const flowNodes = computed<Node<NoteNodeData>[]>(() =>
  visibleNodes.value.map((node) => ({
    id: node.id,
    type: 'note',
    position: { x: node.x, y: node.y },
    width: node.width || 220,
    draggable: false,
    selectable: false,
    data: {
      title: node.title,
      importance: node.importance,
      tags: node.tags,
      tagColors: tagColors.value,
    },
  }))
)

const flowEdges = computed<Edge[]>(() =>
  store.edges
    // to=null (ссылка удалена) и концы без доступа/фильтра — не рисуем
    .filter(
      (edge) =>
        edge.to !== null &&
        edge.state === 'ok' &&
        visibleNodeIds.value.has(edge.from) &&
        visibleNodeIds.value.has(edge.to)
    )
    .map((edge) => ({
      id: edge.id,
      source: edge.from,
      target: edge.to as string,
      type: 'default',
      selectable: false,
      focusable: false,
      deletable: false,
      markerEnd: MarkerType.ArrowClosed,
      class: edge.type === 'indirect' ? 'canvas-edge canvas-edge--indirect' : 'canvas-edge',
      ...(edge.type === 'indirect'
        ? { style: { strokeDasharray: '6 4', strokeWidth: 1.5, opacity: 0.7 } }
        : {}),
    }))
)

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
        <span v-if="brokenEdges > 0" class="canvas-view__broken">
          {{ brokenEdges }} связ. с удалёнными заметками
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
      <VueFlow
        :nodes="flowNodes"
        :edges="flowEdges"
        fit-view-on-init
        :min-zoom="0.2"
        :nodes-draggable="false"
        :nodes-connectable="false"
        :elements-selectable="false"
        :edges-focusable="false"
        :nodes-focusable="false"
        :zoom-on-double-click="false"
      >
        <template #node-note="nodeProps">
          <NoteNode :data="nodeProps.data" />
        </template>

        <Background pattern-color="#cdd4e3" :gap="24" />
        <Controls :show-interactive="false" />
      </VueFlow>

      <!-- Пустой канвас -->
      <div v-if="visibleNodes.length === 0" class="canvas-view__empty">
        <p class="canvas-view__empty-title">Здесь пока нет заметок</p>
        <p class="canvas-view__empty-text">
          Чтение графа работает; создание и редактирование появятся в следующих итерациях (R26–R27).
        </p>
      </div>
    </div>
  </div>
</template>
