<script setup lang="ts">
/**
 * Смена пароля по одноразовому токену из письма (?token=…).
 * Успех отзывает все refresh-токены (api.md) — дальше вход заново.
 */
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { applyApiError } from '@/api/errors'

const route = useRoute()
const router = useRouter()

const MIN_PASSWORD_LENGTH = 8

const password = ref('')
const passwordRepeat = ref('')
const loading = ref(false)
const errors = ref<Record<string, string>>({})

const token = typeof route.query.token === 'string' ? route.query.token : ''

async function onSubmit() {
  if (loading.value) return

  errors.value = {}
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    errors.value.password = `Минимум ${MIN_PASSWORD_LENGTH} символов`
  }
  if (passwordRepeat.value !== password.value) {
    errors.value.passwordRepeat = 'Пароли не совпадают'
  }
  if (Object.keys(errors.value).length > 0) return

  loading.value = true
  try {
    await authApi.resetPassword(token, password.value)
    notify({ title: 'Пароль изменён', description: 'Войдите с новым паролем', status: 'success' })
    await router.push({ name: 'login' })
  } catch (error) {
    applyApiError(error, errors.value)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">Новый пароль</h1>

    <template v-if="token">
      <p class="auth-form__subtitle">Придумайте новый пароль для аккаунта</p>

      <div>
        <UiInput
          id="reset-password"
          v-model="password"
          type="password"
          placeholder="Минимум 8 символов"
          autocomplete="new-password"
          :disabled="loading"
        >
          Новый пароль
        </UiInput>
        <p v-if="errors.password" class="auth-form__error">{{ errors.password }}</p>
      </div>

      <div>
        <UiInput
          id="reset-password-repeat"
          v-model="passwordRepeat"
          type="password"
          placeholder="Ещё раз"
          autocomplete="new-password"
          :disabled="loading"
        >
          Повторите пароль
        </UiInput>
        <p v-if="errors.passwordRepeat" class="auth-form__error">{{ errors.passwordRepeat }}</p>
      </div>

      <UiButton type="submit" :disabled="loading">
        {{ loading ? 'Сохраняем…' : 'Сменить пароль' }}
      </UiButton>
    </template>

    <template v-else>
      <p class="auth-form__status">Ссылка неполная — в ней нет токена. Откройте её из письма целиком.</p>
      <UiButton variant="ghost" to="/forgot-password">Запросить новую ссылку</UiButton>
    </template>

    <div class="auth-form__footer">
      <span>
        Вспомнили пароль?
        <RouterLink to="/login">Войти</RouterLink>
      </span>
    </div>
  </form>
</template>
