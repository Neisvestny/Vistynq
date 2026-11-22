<template>
  <section class="hero">
    <div class="container hero__inner">
      <p class="kicker">{{ t('home.heroKicker') }}</p>

      <h1>
        {{ t('home.heroTitleBefore') }}<span class="accent">{{ t('home.heroTitleAccent') }}</span>
      </h1>

      <p class="lead">{{ t('home.heroLead') }}</p>

      <div class="hero__actions">
        <UiButton v-if="auth.isAuthenticated" size="lg" to="/dashboard">{{
          t('home.openDashboard')
        }}</UiButton>
        <template v-else>
          <UiButton size="lg" to="/register">{{ t('home.register') }}</UiButton>
          <UiButton variant="ghost" size="lg" to="/login">{{ t('home.login') }}</UiButton>
        </template>
        <UiButton variant="ghost" size="lg" href="#how">{{ t('home.howItWorks') }}</UiButton>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '../ui/UiButton.vue'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

onMounted(() => {
  void auth.ensureRestored()
})
</script>
