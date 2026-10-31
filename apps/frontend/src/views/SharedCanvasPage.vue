<script setup lang="ts">
/** Публичный (анонимный) вид канваса — заглушка; данные из /shared/canvases/{slug} (R11/R25). */
import { useRoute } from 'vue-router'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
</script>

<template>
  <div>
    <div class="shared-banner">Публичная версия — только чтение</div>

    <div class="stub-page">
      <header class="stub-page__header">
        <h1 class="stub-page__title">Публичный канвас</h1>
        <span class="stub-badge">Каркас · R04</span>
      </header>

      <UiCard>
        <p class="stub-hint">
          Граф канваса по ссылке <code>{{ route.params.slug }}</code> появится после R11/R25.
        </p>
      </UiCard>
    </div>
  </div>
</template>
