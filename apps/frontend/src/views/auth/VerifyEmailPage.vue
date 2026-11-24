<script setup lang="ts">
/**
 * Verifikatsiya email: pismo ssylaetsya na /verify-email?token=…, token ukhodit
 * v tele POST /auth/verify-email — v URL on tolko dlya routinga (architecture.md §2).
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { applyApiError, isValidEmail } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { focusFirstInvalidField } from '@/lib/formFeedback'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

type State = 'loading' | 'success' | 'error' | 'no-token' | 'sent'
const state = ref<State>('loading')
const formError = ref('')
const tokenParam = typeof route.query.token === 'string' ? route.query.token : ''
const leaving = ref(false)

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const resendEmailError = ref('')
const resendLoading = ref(false)
const resent = ref(false)

/**
 * Knopka «Pereyti ko vkhodu» vsegda otkryvaet formu logina, a ne chuzhie
 * kanvasy: v brauzere mogla ostatsya refresh-kuka ot prezhnego akkaunta,
 * i guard /login (requiresGuest) perebrosil by polzovatelya v dashboard.
 */
async function goToLogin(): Promise<void> {
  if (leaving.value) return
  leaving.value = true
  try {
    await auth.clearSession()
    await router.push({ name: 'login' })
  } finally {
    leaving.value = false
  }
}

async function verify(token: string): Promise<void> {
  state.value = 'loading'
  formError.value = ''
  try {
    await authApi.verifyEmail(token)
    await auth.markEmailVerified()
    state.value = 'success'
  } catch (error) {
    state.value = 'error'
    formError.value = applyApiError(error, {})
  }
}

async function resend(): Promise<void> {
  if (resendLoading.value) return
  resendEmailError.value = ''
  resent.value = false
  formError.value = ''
  const target = email.value.trim()
  if (!isValidEmail(target)) {
    resendEmailError.value = t('auth.emailInvalid')
    void focusFirstInvalidField()
    return
  }

  resendLoading.value = true
  try {
    await authApi.resendVerification(target)
    resent.value = true
  } catch (error) {
    formError.value = applyApiError(error, {})
  } finally {
    resendLoading.value = false
  }
}

onMounted(async () => {
  const token = route.query.token
  if (typeof token === 'string' && token !== '') {
    await verify(token)
    return
  }
  state.value = route.query.sent === '1' ? 'sent' : 'no-token'
})
</script>

<template>
  <div class="auth-form">
    <h1 class="auth-form__title">{{ t('auth.verifyEmailTitle') }}</h1>

    <p v-if="state === 'loading'" class="auth-form__status" role="status">
      {{ t('auth.verifyChecking') }}
    </p>

    <template v-else-if="state === 'success'">
      <p class="auth-form__success" role="status">{{ t('auth.verifySuccess') }}</p>
      <UiButton :disabled="leaving" @click="goToLogin">
        {{ leaving ? t('auth.verifyRedirecting') : t('auth.goToLogin') }}
      </UiButton>
    </template>

    <template v-else>
      <p v-if="state === 'sent'" class="auth-form__status" role="status">
        {{ t('auth.verifySentDescription') }}
      </p>
      <p v-else class="auth-form__status">{{ t('auth.verifyNoToken') }}</p>

      <div v-if="formError" class="auth-form__error-banner" role="alert">
        <span>{{ formError }}</span>
        <button
          v-if="state === 'error' && tokenParam"
          type="button"
          class="auth-form__link-button"
          @click="verify(tokenParam)"
        >
          {{ t('common.retry') }}
        </button>
      </div>

      <UiInput
        id="verify-email"
        v-model="email"
        type="email"
        :placeholder="t('auth.emailPlaceholder')"
        autocomplete="email"
        :disabled="resendLoading"
        :error="resendEmailError"
      >
        {{ t('auth.verifyResendLabel') }}
      </UiInput>

      <p v-if="resent" class="auth-form__success" role="status">{{ t('auth.resendSuccess') }}</p>

      <UiButton type="button" variant="ghost" :disabled="resendLoading" @click="resend">
        {{ resendLoading ? t('common.sending') : t('auth.resendVerification') }}
      </UiButton>

      <UiButton variant="ghost" :disabled="leaving" @click="goToLogin">
        {{ t('auth.goToLogin') }}
      </UiButton>
    </template>
  </div>
</template>
