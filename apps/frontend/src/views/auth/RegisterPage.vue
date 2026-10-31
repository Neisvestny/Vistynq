<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { notify } from '@/api/notify'
import { applyApiError, isValidEmail } from '@/api/errors'

const router = useRouter()
const auth = useAuthStore()

const MIN_PASSWORD_LENGTH = 8

const email = ref('')
const displayName = ref('')
const password = ref('')
const loading = ref(false)
const errors = ref<Record<string, string>>({})

async function onSubmit() {
  if (loading.value) return

  errors.value = {}
  if (!isValidEmail(email.value)) {
    errors.value.email = 'Введите корректный email'
  }
  if (!displayName.value.trim()) {
    errors.value.displayName = 'Введите имя'
  }
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    errors.value.password = `Минимум ${MIN_PASSWORD_LENGTH} символов`
  }
  if (Object.keys(errors.value).length > 0) return

  loading.value = true
  try {
    await auth.register(email.value.trim(), password.value, displayName.value.trim())
    notify({
      title: 'Регистрация почти завершена',
      description: 'Мы отправили письмо для подтверждения email',
      status: 'success',
    })
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
    <h1 class="auth-form__title">Регистрация</h1>
    <p class="auth-form__subtitle">Создайте аккаунт — и письмо придёт на почту для подтверждения</p>

    <div>
      <UiInput
        id="register-name"
        v-model="displayName"
        type="text"
        placeholder="Как вас называть"
        autocomplete="name"
        :disabled="loading"
      >
        Имя
      </UiInput>
      <p v-if="errors.displayName" class="auth-form__error">{{ errors.displayName }}</p>
    </div>

    <div>
      <UiInput
        id="register-email"
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
        id="register-password"
        v-model="password"
        type="password"
        placeholder="Минимум 8 символов"
        autocomplete="new-password"
        :disabled="loading"
      >
        Пароль
      </UiInput>
      <p v-if="errors.password" class="auth-form__error">{{ errors.password }}</p>
    </div>

    <UiButton type="submit" :disabled="loading">
      {{ loading ? 'Создаём аккаунт…' : 'Зарегистрироваться' }}
    </UiButton>

    <div class="auth-form__footer">
      <span>
        Уже есть аккаунт?
        <RouterLink to="/login">Войти</RouterLink>
      </span>
    </div>
  </form>
</template>
