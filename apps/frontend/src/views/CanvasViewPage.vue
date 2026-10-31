<script setup lang="ts">
/**
 * CanvasView (docs/ui-ux.md §2) — заглушка каркаса:
 * доска Vue Flow, ноды/рёбра из GET /canvases/{id}/graph появятся в R25.
 */
import { useRoute } from 'vue-router'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
const canvasId = route.params.id
</script>

<template>
  <div class="stub-page">
    <header class="stub-page__header">
      <h1 class="stub-page__title">Канвас</h1>
      <span class="stub-badge">Каркас · R04</span>
    </header>

    <UiCard>
      <p class="stub-hint">
        Доска с нодами и связями появится в R25 (Vue Flow). ID канваса:
        <code>{{ canvasId }}</code>
      </p>
      <p class="stub-hint">Загрузка графа — GET /canvases/{{ canvasId }}/graph (R11).</p>
    </UiCard>
  </div>
</template>
