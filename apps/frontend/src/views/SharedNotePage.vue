<script setup lang="ts">
/** Публичная (анонимная) заметка — заглушка; рендер markdown появится в R11/R23. */
import { useRoute } from 'vue-router'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
</script>

<template>
  <div>
    <div class="shared-banner">Публичная версия — только чтение</div>

    <div class="stub-page">
      <header class="stub-page__header">
        <h1 class="stub-page__title">Публичная заметка</h1>
        <span class="stub-badge">Каркас · R04</span>
      </header>

      <UiCard>
        <p class="stub-hint">
          Заметка <code>{{ route.params.noteId }}</code> канваса
          <code>{{ route.params.slug }}</code> с рендером markdown появится после R11/R23.
        </p>
      </UiCard>
    </div>
  </div>
</template>
