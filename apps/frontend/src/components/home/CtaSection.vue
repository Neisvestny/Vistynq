<template>
  <section id="cta" class="cta">
    <div class="container cta__inner">
      <div>
        <h2>{{ t('home.ctaTitle') }}</h2>
        <p>{{ t('home.ctaText') }}</p>
      </div>

      <div class="cta__actions">
        <UiButton v-if="auth.isAuthenticated" size="lg" to="/dashboard">{{
          t('home.openDashboard')
        }}</UiButton>
        <template v-else>
          <UiButton size="lg" to="/register">{{ t('home.register') }}</UiButton>
          <UiButton variant="ghost" size="lg" to="/login">{{ t('home.login') }}</UiButton>
        </template>
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
