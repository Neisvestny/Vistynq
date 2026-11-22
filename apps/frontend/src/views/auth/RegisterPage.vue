<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { notify } from '@/api/notify'
import { applyApiError, isValidEmail } from '@/api/errors'

const { t } = useI18n()
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
    errors.value.email = t('auth.emailInvalid')
  }
  if (!displayName.value.trim()) {
    errors.value.displayName = t('auth.displayNameRequired')
  }
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    errors.value.password = t('auth.passwordMin', { min: MIN_PASSWORD_LENGTH })
  }
  if (Object.keys(errors.value).length > 0) return

  loading.value = true
  try {
    await auth.register(email.value.trim(), password.value, displayName.value.trim())
    notify({
      title: t('auth.registerSuccessTitle'),
      description: t('auth.registerSuccessDescription'),
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
    <h1 class="auth-form__title">{{ t('auth.registerTitle') }}</h1>
    <p class="auth-form__subtitle">{{ t('auth.registerSubtitle') }}</p>

    <div>
      <UiInput
        id="register-name"
        v-model="displayName"
        type="text"
        :placeholder="t('auth.namePlaceholder')"
        autocomplete="name"
        :disabled="loading"
      >
        {{ t('auth.displayName') }}
      </UiInput>
      <p v-if="errors.displayName" class="auth-form__error">{{ errors.displayName }}</p>
    </div>

    <div>
      <UiInput
        id="register-email"
        v-model="email"
        type="email"
        :placeholder="t('auth.emailPlaceholder')"
        autocomplete="email"
        :disabled="loading"
      >
        {{ t('auth.email') }}
      </UiInput>
      <p v-if="errors.email" class="auth-form__error">{{ errors.email }}</p>
    </div>

    <div>
      <UiInput
        id="register-password"
        v-model="password"
        type="password"
        :placeholder="t('auth.passwordMinPlaceholder')"
        autocomplete="new-password"
        :disabled="loading"
      >
        {{ t('auth.password') }}
      </UiInput>
      <p v-if="errors.password" class="auth-form__error">{{ errors.password }}</p>
    </div>

    <UiButton type="submit" :disabled="loading">
      {{ loading ? t('auth.registering') : t('auth.signUp') }}
    </UiButton>

    <div class="auth-form__footer">
      <span>
        {{ t('auth.haveAccountPrompt') }}
        <RouterLink to="/login">{{ t('auth.signIn') }}</RouterLink>
      </span>
    </div>
  </form>
</template>
