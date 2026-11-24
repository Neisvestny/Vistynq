<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { applyApiError, isValidEmail } from '@/api/errors'
import { focusFirstInvalidField } from '@/lib/formFeedback'

const { t } = useI18n()

const email = ref('')
const loading = ref(false)
const error = ref('')
const formError = ref('')
const sent = ref(false)

async function onSubmit() {
  if (loading.value) return

  error.value = ''
  formError.value = ''
  if (!isValidEmail(email.value)) {
    error.value = t('auth.emailInvalid')
    void focusFirstInvalidField()
    return
  }

  loading.value = true
  try {
    // Server vsegda otvechaet 202 — bez utechki sushchestvovaniya email (api.md).
    await authApi.forgotPassword(email.value.trim())
    sent.value = true
  } catch (apiError) {
    formError.value = applyApiError(apiError, {})
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">{{ t('auth.forgotTitle') }}</h1>
    <p class="auth-form__subtitle">{{ t('auth.forgotSubtitle') }}</p>

    <div v-if="formError" class="auth-form__error-banner" role="alert">
      <span>{{ formError }}</span>
      <button type="button" class="auth-form__link-button" @click="onSubmit">
        {{ t('common.retry') }}
      </button>
    </div>

    <template v-if="sent">
      <p class="auth-form__success" role="status">{{ t('auth.forgotSent') }}</p>
      <UiButton type="button" variant="ghost" :disabled="loading" @click="onSubmit">
        {{ loading ? t('common.sending') : t('auth.forgotSendAgain') }}
      </UiButton>
    </template>

    <template v-else>
      <UiInput
        id="forgot-email"
        v-model="email"
        type="email"
        :placeholder="t('auth.emailPlaceholder')"
        autocomplete="email"
        :disabled="loading"
        :error="error"
      >
        {{ t('auth.email') }}
      </UiInput>

      <UiButton type="submit" :disabled="loading">
        {{ loading ? t('auth.forgotSending') : t('auth.forgotSubmit') }}
      </UiButton>
    </template>

    <div class="auth-form__footer">
      <span>
        {{ t('auth.forgotRemembered') }}
        <RouterLink to="/login">{{ t('auth.signIn') }}</RouterLink>
      </span>
    </div>
  </form>
</template>
