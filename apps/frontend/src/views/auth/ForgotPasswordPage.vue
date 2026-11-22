<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { authApi } from '@/api/auth'
import { notify } from '@/api/notify'
import { applyApiError, isValidEmail } from '@/api/errors'

const { t } = useI18n()
const router = useRouter()

const email = ref('')
const loading = ref(false)
const error = ref('')

async function onSubmit() {
  if (loading.value) return

  error.value = ''
  if (!isValidEmail(email.value)) {
    error.value = t('auth.emailInvalid')

    return
  }

  loading.value = true
  try {
    // Server vsegda otvechaet 202 — bez utechki sushchestvovaniya email (api.md).
    await authApi.forgotPassword(email.value.trim())
    notify({
      title: t('auth.forgotNotifyTitle'),
      description: t('auth.forgotNotifyDescription'),
      status: 'success',
    })
    await router.push({ name: 'login' })
  } catch (apiError) {
    applyApiError(apiError, {})
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="onSubmit">
    <h1 class="auth-form__title">{{ t('auth.forgotTitle') }}</h1>
    <p class="auth-form__subtitle">{{ t('auth.forgotSubtitle') }}</p>

    <div>
      <UiInput
        id="forgot-email"
        v-model="email"
        type="email"
        :placeholder="t('auth.emailPlaceholder')"
        autocomplete="email"
        :disabled="loading"
      >
        {{ t('auth.email') }}
      </UiInput>
      <p v-if="error" class="auth-form__error">{{ error }}</p>
    </div>

    <UiButton type="submit" :disabled="loading">
      {{ loading ? t('auth.forgotSending') : t('auth.forgotSubmit') }}
    </UiButton>

    <div class="auth-form__footer">
      <span>
        {{ t('auth.forgotRemembered') }}
        <RouterLink to="/login">{{ t('auth.signIn') }}</RouterLink>
      </span>
    </div>
  </form>
</template>
