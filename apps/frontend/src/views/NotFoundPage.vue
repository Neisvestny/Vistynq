<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import AppBrand from '@/components/AppBrand.vue'

const { t } = useI18n()
</script>

<template>
  <div class="auth-layout">
    <header class="auth-layout__header">
      <AppBrand />
    </header>

    <main class="auth-layout__main">
      <UiCard class="auth-layout__card">
        <div class="auth-form">
          <h1 class="auth-form__title">{{ t('notFound.title') }}</h1>
          <p class="auth-form__status">{{ t('notFound.description') }}</p>
          <UiButton to="/">{{ t('notFound.home') }}</UiButton>
        </div>
      </UiCard>
    </main>
  </div>
</template>
