<script setup lang="ts">
/** Профиль (R14): имя/email/верификация из auth-store; правки профиля и аватара — позже. */
import { useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'home' })
}
</script>

<template>
  <div class="stub-page">
    <header class="stub-page__header">
      <h1 class="stub-page__title">Профиль</h1>
    </header>

    <UiCard v-if="auth.user">
      <div class="stub-section">
        <p><strong>Имя:</strong> {{ auth.user.displayName }}</p>
        <p><strong>Email:</strong> {{ auth.user.email }}</p>
        <p>
          <strong>Подтверждение email:</strong>
          {{ auth.user.emailVerified ? 'подтверждён' : 'не подтверждён' }}
        </p>
        <div>
          <UiButton variant="ghost" @click="onLogout">Выйти</UiButton>
        </div>
      </div>
    </UiCard>

    <UiCard v-else>
      <p class="stub-hint">Данные пользователя не загружены — войдите заново.</p>
    </UiCard>
  </div>
</template>
