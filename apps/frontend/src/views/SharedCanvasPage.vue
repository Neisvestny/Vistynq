<script setup lang="ts">
/** Публичный (анонимный) вид канваса — read-only (R32). */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MarkerType } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { ApiError } from '@/api/http'
import { sharedApi } from '@/api/shared'
import type { GraphResponse } from '@/api/types'
import CanvasBoard from '@/components/canvas/CanvasBoard.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import type { NoteNodeData } from '@/components/canvas/NoteNode.vue'
import type { LinkEdgeData } from '@/composables/useGraph'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref<ApiError | null>(null)
const graph = ref<GraphResponse | null>(null)

async function load(): Promise<void> {
  const slug = String(route.params.slug ?? '')
  if (!slug) {
    error.value = new ApiError(404, 'NOT_FOUND', 'Канвас не найден')
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    graph.value = await sharedApi.canvas(slug)
  } catch (e) {
    error.value = e instanceof ApiError ? e : ApiError.network()
  } finally {
    loading.value = false
  }
}

const tagColors = computed<Record<string, string>>(() =>
  Object.fromEntries((graph.value?.tags ?? []).map((tag) => [tag.name, tag.color]))
)

const flowNodes = computed<Node<NoteNodeData>[]>(() =>
  (graph.value?.nodes ?? []).map((node) => ({
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
      visibility: node.visibility,
      authorId: node.authorId,
      isRelevant: node.isRelevant,
      dimmed: false,
      canMove: false,
    },
  }))
)

const flowEdges = computed<Edge<LinkEdgeData>[]>(() => {
  const visibleIds = new Set((graph.value?.nodes ?? []).map((n) => n.id))
  return (graph.value?.edges ?? []).flatMap((edge) => {
    if (edge.state === 'removed') return []
    if (!edge.to || !visibleIds.has(edge.from) || !visibleIds.has(edge.to)) return []
    const isIndirect = edge.type === 'indirect'
    return [
      {
        id: edge.id,
        source: edge.from,
        target: edge.to,
        type: 'linkEdge',
        selectable: false,
        focusable: false,
        deletable: false,
        markerEnd: MarkerType.ArrowClosed,
        class: ['canvas-edge', isIndirect ? 'canvas-edge--indirect' : 'canvas-edge--direct']
          .filter(Boolean)
          .join(' '),
        ...(isIndirect
          ? { style: { strokeDasharray: '6 4', strokeWidth: 1.5, opacity: 0.7 } }
          : {}),
        data: {
          edgeId: edge.id,
          edgeType: edge.type,
          state: edge.state,
          from: edge.from,
          to: edge.to,
          label: edge.label,
          dangling: false,
          canRestore: false,
        },
      } as Edge<LinkEdgeData>,
    ]
  })
})

function openSharedNote(noteId: string): void {
  router.push({
    name: 'shared-note',
    params: { slug: route.params.slug, noteId },
  })
}

function onNodeClick(payload: { event: MouseEvent; nodeId: string }): void {
  openSharedNote(payload.nodeId)
}

function onNodeOpen(noteId: string): void {
  openSharedNote(noteId)
}

onMounted(load)
watch(() => route.params.slug, load)
</script>

<template>
  <div>
    <div class="shared-banner">Публичная версия — только чтение</div>

    <div class="shared-canvas-page">
      <div v-if="loading" class="shared-canvas-page__state" aria-busy="true">Загрузка…</div>

      <div v-else-if="error" class="shared-canvas-page__state">
        <UiCard class="shared-canvas-page__card">
          <h1 class="shared-canvas-page__title">
            {{ error.status === 404 ? 'Канвас недоступен' : 'Не удалось загрузить канвас' }}
          </h1>
          <p class="shared-canvas-page__muted">{{ error.message }}</p>
          <div class="shared-canvas-page__actions">
            <UiButton v-if="error.status !== 404" size="sm" @click="load">Повторить</UiButton>
          </div>
        </UiCard>
      </div>

      <div v-else-if="graph" class="shared-canvas-page__content">
        <header class="shared-canvas-page__header">
          <div>
            <h1 class="shared-canvas-page__title">{{ graph.canvas.title }}</h1>
            <p class="shared-canvas-page__muted">{{ flowNodes.length }} заметок</p>
          </div>
        </header>

        <div class="shared-canvas-page__board">
          <CanvasBoard
            :nodes="flowNodes"
            :edges="flowEdges"
            :layout-editable="false"
            :edges-selectable="false"
            :linking="false"
            @node-click="onNodeClick"
            @node-open="onNodeOpen"
          >
            <div v-if="flowNodes.length === 0" class="shared-canvas-page__empty">
              <p class="shared-canvas-page__empty-title">
                В этом канвасе пока нет публичных заметок
              </p>
            </div>
          </CanvasBoard>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.shared-canvas-page {
  min-height: calc(100vh - 56px);
}
.shared-canvas-page__state {
  max-width: 900px;
  margin: 0 auto;
  padding: 2rem 1rem;
}
.shared-canvas-page__card {
  display: grid;
  gap: 0.75rem;
}
.shared-canvas-page__content {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  min-height: calc(100vh - 56px);
}
.shared-canvas-page__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface);
}
.shared-canvas-page__title {
  margin: 0;
  font-size: clamp(1.25rem, 2vw, 1.75rem);
  line-height: 1.2;
}
.shared-canvas-page__muted {
  margin: 0.25rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
.shared-canvas-page__actions {
  display: flex;
  gap: 0.5rem;
}
.shared-canvas-page__board {
  min-height: 560px;
  height: calc(100vh - 140px);
}
.shared-canvas-page__empty {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  pointer-events: none;
  padding: 1rem;
}
.shared-canvas-page__empty-title {
  margin: 0;
  padding: 0.75rem 1rem;
  border-radius: 10px;
  background: color-mix(in srgb, var(--color-surface) 90%, transparent);
  color: var(--color-text-muted);
}
</style>
