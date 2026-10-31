<script setup lang="ts">
/**
 * Верификация email: письмо ссылается на /verify-email?token=…, токен уходит
 * в теле POST /auth/verify-email — в URL он только для роутинга (architecture.md §2).
 */
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { applyApiError } from '@/api/errors'

const route = useRoute()

const state = ref<'loading' | 'success' | 'error' | 'no-token'>('loading')
const errorMessage = ref('')

onMounted(async () => {
  const token = route.query.token
  if (typeof token !== 'string' || token === '') {
    state.value = 'no-token'

    return
  }

  try {
    await authApi.verifyEmail(token)
    state.value = 'success'
    notify({ title: 'Email подтверждён', description: 'Теперь можно войти', status: 'success' })
  } catch (error) {
    state.value = 'error'
    if (error instanceof Error) {
      errorMessage.value = error.message
    }
    applyApiError(error, {})
  }
})
</script>

<template>
  <div class="auth-form">
    <h1 class="auth-form__title">Подтверждение email</h1>

    <p v-if="state === 'loading'" class="auth-form__status">Проверяем ссылку…</p>

    <template v-else-if="state === 'success'">
      <p class="auth-form__status">Почта подтверждена. Теперь можно войти в аккаунт.</p>
      <UiButton to="/login">Перейти ко входу</UiButton>
    </template>

    <template v-else-if="state === 'no-token'">
      <p class="auth-form__status">
        Ссылка неполная — в ней нет токена. Откройте её из письма целиком.
      </p>
      <UiButton variant="ghost" to="/login">Перейти ко входу</UiButton>
    </template>

    <template v-else>
      <p class="auth-form__status">
        Ссылка недействительна или устарела{{ errorMessage ? `: ${errorMessage}` : '' }}.
      </p>
      <UiButton variant="ghost" to="/login">Перейти ко входу</UiButton>
    </template>
  </div>
</template>
