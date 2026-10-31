<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { applyApiError, isValidEmail } from '@/api/errors'

const router = useRouter()

const email = ref('')
const loading = ref(false)
const error = ref('')

async function onSubmit() {
  if (loading.value) return

  error.value = ''
  if (!isValidEmail(email.value)) {
    error.value = 'Введите корректный email'

    return
  }

  loading.value = true
  try {
    // Сервер всегда отвечает 202 — без утечки существования email (api.md).
    await authApi.forgotPassword(email.value.trim())
    notify({
      title: 'Проверьте почту',
      description: 'Если аккаунт с таким email есть, письмо со сбросом уже отправлено',
      status: 'success',
    })
    await router.push({ name: 'login' })
  } catch (apiError) {
    applyApiError(apiError, {})
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">Сброс пароля</h1>
    <p class="auth-form__subtitle">Пришлём ссылку для смены пароля на вашу почту</p>

    <div>
      <UiInput
        id="forgot-email"
        v-model="email"
        type="email"
        placeholder="you@example.com"
        autocomplete="email"
        :disabled="loading"
      >
        Email
      </UiInput>
      <p v-if="error" class="auth-form__error">{{ error }}</p>
    </div>

    <UiButton type="submit" :disabled="loading">
      {{ loading ? 'Отправляем…' : 'Отправить ссылку' }}
    </UiButton>

    <div class="auth-form__footer">
      <span>
        Вспомнили пароль?
        <RouterLink to="/login">Войти</RouterLink>
      </span>
    </div>
  </form>
</template>
