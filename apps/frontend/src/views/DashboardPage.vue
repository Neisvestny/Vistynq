<script setup lang="ts">
/**
 * Dashboard (docs/ui-ux.md §1) — заглушка каркаса:
 * данные появятся в R11 (Canvas CRUD + Graph API).
 */
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="stub-page">
    <header class="stub-page__header">
      <h1 class="stub-page__title">
        Привет{{ auth.user ? `, ${auth.user.displayName}` : '' }}!
      </h1>
      <span class="stub-badge">Каркас · R04</span>
    </header>

    <section class="stub-section">
      <div class="stub-section__header">
        <h2 class="stub-section__heading">Мои канвасы</h2>
        <UiButton size="sm" disabled>+ Создать канвас</UiButton>
      </div>
      <p class="stub-hint">
        Список канвасов появится здесь после R11 — вместе с созданием, ролями и публичными ссылками.
      </p>
      <div class="stub-grid">
        <UiCard v-for="n in 2" :key="n">
          <p class="stub-hint">Канвас {{ n }} (заглушка)</p>
        </UiCard>
      </div>
    </section>

    <section class="stub-section">
      <h2 class="stub-section__heading">Приглашения</h2>
      <p class="stub-hint">Входящие инвайты с кнопками «Принять»/«Отклонить» — R11.</p>
    </section>
  </div>
</template>
