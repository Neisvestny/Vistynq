<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { applyApiError, isValidEmail } from '@/api/errors'
import { focusFirstInvalidField } from '@/lib/formFeedback'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const MIN_PASSWORD_LENGTH = 8

const email = ref('')
const displayName = ref('')
const password = ref('')
const loading = ref(false)
const errors = ref<Record<string, string>>({})
const formError = ref('')

async function onSubmit() {
  if (loading.value) return

  errors.value = {}
  formError.value = ''
  if (!isValidEmail(email.value)) {
    errors.value.email = t('auth.emailInvalid')
  }
  if (!displayName.value.trim()) {
    errors.value.displayName = t('auth.displayNameRequired')
  }
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    errors.value.password = t('auth.passwordMin', { min: MIN_PASSWORD_LENGTH })
  }
  if (Object.keys(errors.value).length > 0) {
    void focusFirstInvalidField()
    return
  }

  loading.value = true
  try {
    await auth.register(email.value.trim(), password.value, displayName.value.trim())
    await router.push({
      name: 'verify-email',
      query: { sent: '1', email: email.value.trim() },
    })
  } catch (error) {
    formError.value = applyApiError(error, errors.value)
    void focusFirstInvalidField()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">{{ t('auth.registerTitle') }}</h1>
    <p class="auth-form__subtitle">{{ t('auth.registerSubtitle') }}</p>

    <div v-if="formError" class="auth-form__error-banner" role="alert">
      <span>{{ formError }}</span>
      <button type="button" class="auth-form__link-button" @click="onSubmit">
        {{ t('common.retry') }}
      </button>
    </div>

    <UiInput
      id="register-name"
      v-model="displayName"
      type="text"
      :placeholder="t('auth.namePlaceholder')"
      autocomplete="name"
      :disabled="loading"
      :error="errors.displayName"
    >
      {{ t('auth.displayName') }}
    </UiInput>

    <UiInput
      id="register-email"
      v-model="email"
      type="email"
      :placeholder="t('auth.emailPlaceholder')"
      autocomplete="email"
      :disabled="loading"
      :error="errors.email"
    >
      {{ t('auth.email') }}
    </UiInput>

    <UiInput
      id="register-password"
      v-model="password"
      type="password"
      :placeholder="t('auth.passwordMinPlaceholder')"
      autocomplete="new-password"
      :disabled="loading"
      :error="errors.password"
    >
      {{ t('auth.password') }}
    </UiInput>

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
