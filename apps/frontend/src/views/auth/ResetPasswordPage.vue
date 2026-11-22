<script setup lang="ts">
/**
 * Smena parolya po odnorazovomu tokenu iz pisma (?token=…).
 * Uspekh otzyvaet vse refresh-tokeny (api.md) — dalshe vkhod zanovo.
 */
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { applyApiError } from '@/api/errors'

const { t } = useI18n()
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
    errors.value.password = t('auth.passwordMin', { min: MIN_PASSWORD_LENGTH })
  }
  if (passwordRepeat.value !== password.value) {
    errors.value.passwordRepeat = t('auth.passwordsMismatch')
  }
  if (Object.keys(errors.value).length > 0) return

  loading.value = true
  try {
    await authApi.resetPassword(token, password.value)
    notify({
      title: t('auth.resetNotifyTitle'),
      description: t('auth.resetNotifyDescription'),
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
    <h1 class="auth-form__title">{{ t('auth.resetTitle') }}</h1>

    <template v-if="token">
      <p class="auth-form__subtitle">{{ t('auth.resetSubtitle') }}</p>

      <div>
        <UiInput
          id="reset-password"
          v-model="password"
          type="password"
          :placeholder="t('auth.passwordMinPlaceholder')"
          autocomplete="new-password"
          :disabled="loading"
        >
          {{ t('auth.newPassword') }}
        </UiInput>
        <p v-if="errors.password" class="auth-form__error">{{ errors.password }}</p>
      </div>

      <div>
        <UiInput
          id="reset-password-repeat"
          v-model="passwordRepeat"
          type="password"
          :placeholder="t('auth.passwordPlaceholder')"
          autocomplete="new-password"
          :disabled="loading"
        >
          {{ t('auth.confirmPassword') }}
        </UiInput>
        <p v-if="errors.passwordRepeat" class="auth-form__error">{{ errors.passwordRepeat }}</p>
      </div>

      <UiButton type="submit" :disabled="loading">
        {{ loading ? t('auth.resetSaving') : t('auth.resetSubmit') }}
      </UiButton>
    </template>

    <template v-else>
      <p class="auth-form__status">{{ t('auth.resetNoToken') }}</p>
      <UiButton variant="ghost" to="/forgot-password">{{ t('auth.resetRequestAgain') }}</UiButton>
    </template>

    <div class="auth-form__footer">
      <span>
        {{ t('auth.resetRemembered') }}
        <RouterLink to="/login">{{ t('auth.signIn') }}</RouterLink>
      </span>
    </div>
  </form>
</template>
