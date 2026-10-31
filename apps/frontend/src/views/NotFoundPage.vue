<script setup lang="ts">
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import AppBrand from '@/components/AppBrand.vue'
</script>

<template>
  <div class="auth-layout">
    <header class="auth-layout__header">
      <AppBrand />
    </header>

    <main class="auth-layout__main">
      <UiCard class="auth-layout__card">
        <div class="auth-form">
          <h1 class="auth-form__title">404 — страница не найдена</h1>
          <p class="auth-form__status">Возможно, ссылка устарела или в адресе опечатка.</p>
          <UiButton to="/">На главную</UiButton>
        </div>
      </UiCard>
    </main>
  </div>
</template>
