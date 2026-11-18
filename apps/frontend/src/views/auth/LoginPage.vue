<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import { applyApiError, isValidEmail } from '@/api/errors'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const errors = ref<Record<string, string>>({})

const showResend = ref(false)
const resendEmail = ref('')
const resendLoading = ref(false)
const resendError = ref('')
const resendSuccess = ref('')

function openResend(prefill?: string) {
  resendSuccess.value = ''
  resendError.value = ''
  if (prefill?.trim()) {
    resendEmail.value = prefill.trim()
  } else if (!resendEmail.value && email.value.trim()) {
    resendEmail.value = email.value.trim()
  }
  showResend.value = true
}

async function onResend() {
  if (resendLoading.value) return

  resendError.value = ''
  resendSuccess.value = ''

  const target = resendEmail.value.trim()
  if (!isValidEmail(target)) {
    resendError.value = 'Введите корректный email'
    return
  }

  resendLoading.value = true
  try {
    await authApi.resendVerification(target)
    resendSuccess.value =
      'Если аккаунт с таким email существует и ещё не подтверждён, мы отправили письмо повторно.'
    notify({ title: 'Письмо отправлено', description: 'Проверьте почту', status: 'success' })
  } catch (error) {
    if (error instanceof ApiError && error.details) {
      const details = error.details.email?.[0]
      if (details) {
        resendError.value = details
        return
      }
    }
    applyApiError(error, {})
  } finally {
    resendLoading.value = false
  }
}

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
    if (error instanceof ApiError && error.code === 'EMAIL_NOT_VERIFIED') {
      notify({
        title: 'Email не подтверждён',
        description: 'Подтвердите почту по ссылке из письма или отправьте письмо ещё раз.',
        status: 'error',
      })
      openResend(email.value)
    } else {
      applyApiError(error, errors.value)
    }
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

    <div class="auth-form__resend">
      <button v-if="!showResend" type="button" class="auth-form__link-button" @click="openResend()">
        Не пришло письмо с подтверждением?
      </button>

      <div v-else class="auth-form__resend-panel">
        <p class="auth-form__hint">Укажите email — мы отправим письмо для подтверждения ещё раз.</p>
        <UiInput
          id="login-resend-email"
          v-model="resendEmail"
          type="email"
          placeholder="you@example.com"
          autocomplete="email"
          :disabled="resendLoading"
        >
          Email для письма
        </UiInput>
        <p v-if="resendError" class="auth-form__error">{{ resendError }}</p>
        <p v-if="resendSuccess" class="auth-form__success">{{ resendSuccess }}</p>
        <div class="auth-form__resend-actions">
          <UiButton
            type="button"
            variant="ghost"
            size="sm"
            :disabled="resendLoading"
            @click="onResend"
          >
            {{ resendLoading ? 'Отправляем…' : 'Отправить письмо ещё раз' }}
          </UiButton>
        </div>
      </div>
    </div>

    <div class="auth-form__footer">
      <RouterLink to="/forgot-password">Забыли пароль?</RouterLink>
      <span>
        Нет аккаунта?
        <RouterLink to="/register">Зарегистрироваться</RouterLink>
      </span>
    </div>
  </form>
</template>
