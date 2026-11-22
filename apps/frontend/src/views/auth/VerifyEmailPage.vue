<script setup lang="ts">
/**
 * Verifikatsiya email: pismo ssylaetsya na /verify-email?token=…, token ukhodit
 * v tele POST /auth/verify-email — v URL on tolko dlya routinga (architecture.md §2).
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { applyApiError } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const state = ref<'loading' | 'success' | 'error' | 'no-token'>('loading')
const errorMessage = ref('')
const leaving = ref(false)

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

onMounted(async () => {
  const token = route.query.token
  if (typeof token !== 'string' || token === '') {
    state.value = 'no-token'

    return
  }

  try {
    await authApi.verifyEmail(token)
    state.value = 'success'
    notify({
      title: t('auth.verifySuccessNotifyTitle'),
      description: t('auth.verifySuccessNotifyDescription'),
      status: 'success',
    })
  } catch (error) {
    state.value = 'error'
    if (error instanceof Error) {
      errorMessage.value = error.message
    }
    applyApiError(error, {})
  }
})
</script>

<template>
  <div class="auth-form">
    <h1 class="auth-form__title">{{ t('auth.verifyEmailTitle') }}</h1>

    <p v-if="state === 'loading'" class="auth-form__status">{{ t('auth.verifyChecking') }}</p>

    <template v-else-if="state === 'success'">
      <p class="auth-form__status">{{ t('auth.verifySuccess') }}</p>
      <UiButton :disabled="leaving" @click="goToLogin">
        {{ leaving ? t('auth.verifyRedirecting') : t('auth.goToLogin') }}
      </UiButton>
    </template>

    <template v-else-if="state === 'no-token'">
      <p class="auth-form__status">{{ t('auth.verifyNoToken') }}</p>
      <UiButton variant="ghost" to="/login">{{ t('auth.goToLogin') }}</UiButton>
    </template>

    <template v-else>
      <p class="auth-form__status">
        {{ t('auth.verifyInvalid') }}{{ errorMessage ? `: ${errorMessage}` : '' }}.
      </p>
      <UiButton variant="ghost" to="/login">{{ t('auth.goToLogin') }}</UiButton>
    </template>
  </div>
</template>
