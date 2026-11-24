<script setup lang="ts">
/**
 * Smena parolya po odnorazovomu tokenu iz pisma (?token=…).
 * Uspekh otzyvaet vse refresh-tokeny (api.md) — dalshe vkhod zanovo.
 */
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { ApiError } from '@/api/http'
import { applyApiError } from '@/api/errors'
import { focusFirstInvalidField } from '@/lib/formFeedback'

const { t } = useI18n()
const route = useRoute()

const MIN_PASSWORD_LENGTH = 8

const password = ref('')
const passwordRepeat = ref('')
const loading = ref(false)
const errors = ref<Record<string, string>>({})
const formError = ref('')
const tokenInvalid = ref(false)
const success = ref(false)

const token = typeof route.query.token === 'string' ? route.query.token : ''

async function onSubmit() {
  if (loading.value) return

  errors.value = {}
  formError.value = ''
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    errors.value.password = t('auth.passwordMin', { min: MIN_PASSWORD_LENGTH })
  }
  if (passwordRepeat.value !== password.value) {
    errors.value.passwordRepeat = t('auth.passwordsMismatch')
  }
  if (Object.keys(errors.value).length > 0) {
    void focusFirstInvalidField()
    return
  }

  loading.value = true
  try {
    await authApi.resetPassword(token, password.value)
    success.value = true
  } catch (error) {
    if (
      error instanceof ApiError &&
      ['INVALID_TOKEN', 'TOKEN_EXPIRED', 'BAD_TOKEN', 'NOT_FOUND'].includes(error.code)
    ) {
      tokenInvalid.value = true
      return
    }
    formError.value = applyApiError(error, errors.value)
    void focusFirstInvalidField()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">{{ t('auth.resetTitle') }}</h1>

    <template v-if="success">
      <p class="auth-form__success" role="status">{{ t('auth.resetSuccess') }}</p>
      <UiButton to="/login">{{ t('auth.goToLogin') }}</UiButton>
    </template>

    <template v-else-if="!token || tokenInvalid">
      <p class="auth-form__status">{{ t('auth.resetNoToken') }}</p>
      <UiButton variant="ghost" to="/forgot-password">{{ t('auth.resetRequestAgain') }}</UiButton>
    </template>

    <template v-else>
      <p class="auth-form__subtitle">{{ t('auth.resetSubtitle') }}</p>

      <div v-if="formError" class="auth-form__error-banner" role="alert">
        <span>{{ formError }}</span>
        <button type="button" class="auth-form__link-button" @click="onSubmit">
          {{ t('common.retry') }}
        </button>
      </div>

      <UiInput
        id="reset-password"
        v-model="password"
        type="password"
        :placeholder="t('auth.passwordMinPlaceholder')"
        autocomplete="new-password"
        :disabled="loading"
        :error="errors.password"
      >
        {{ t('auth.newPassword') }}
      </UiInput>

      <UiInput
        id="reset-password-repeat"
        v-model="passwordRepeat"
        type="password"
        :placeholder="t('auth.passwordPlaceholder')"
        autocomplete="new-password"
        :disabled="loading"
        :error="errors.passwordRepeat"
      >
        {{ t('auth.confirmPassword') }}
      </UiInput>

      <UiButton type="submit" :disabled="loading">
        {{ loading ? t('auth.resetSaving') : t('auth.resetSubmit') }}
      </UiButton>
    </template>

    <div class="auth-form__footer">
      <span>
        {{ t('auth.resetRemembered') }}
        <RouterLink to="/login">{{ t('auth.signIn') }}</RouterLink>
      </span>
    </div>
  </form>
</template>
