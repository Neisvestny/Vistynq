<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { applyApiError, isValidEmail } from '@/api/errors'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const errors = ref<Record<string, string>>({})

async function onSubmit() {
  if (loading.value) return

  errors.value = {}
  if (!isValidEmail(email.value)) {
    errors.value.email = 'Введите корректный email'
  }
  if (!password.value) {
    errors.value.password = 'Введите пароль'
  }
  if (Object.keys(errors.value).length > 0) return

  loading.value = true
  try {
    await auth.login(email.value.trim(), password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    await router.replace(redirect)
  } catch (error) {
    applyApiError(error, errors.value)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">Вход</h1>
    <p class="auth-form__subtitle">Войдите, чтобы открыть свои канвасы</p>

    <div>
      <UiInput
        id="login-email"
        v-model="email"
        type="email"
        placeholder="you@example.com"
        autocomplete="email"
        :disabled="loading"
      >
        Email
      </UiInput>
      <p v-if="errors.email" class="auth-form__error">{{ errors.email }}</p>
    </div>

    <div>
      <UiInput
        id="login-password"
        v-model="password"
        type="password"
        placeholder="••••••••"
        autocomplete="current-password"
        :disabled="loading"
      >
        Пароль
      </UiInput>
      <p v-if="errors.password" class="auth-form__error">{{ errors.password }}</p>
    </div>

    <UiButton type="submit" :disabled="loading">
      {{ loading ? 'Входим…' : 'Войти' }}
    </UiButton>

    <div class="auth-form__footer">
      <RouterLink to="/forgot-password">Забыли пароль?</RouterLink>
      <span>
        Нет аккаунта?
        <RouterLink to="/register">Зарегистрироваться</RouterLink>
      </span>
    </div>
  </form>
</template>
