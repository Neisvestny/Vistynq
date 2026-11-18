<script setup lang="ts">
/** Профиль (R23): имя, email, pendingEmail и базовое редактирование. */
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { applyApiError, isValidEmail } from '@/api/errors'
import { notify } from '@/api/notify'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  displayName: '',
  email: '',
})

const saving = ref(false)
const errors = ref<Record<string, string>>({})

function syncForm() {
  form.displayName = auth.user?.displayName ?? ''
  form.email = auth.user?.email ?? ''
}

watch(
  () => auth.user,
  () => syncForm(),
  { immediate: true }
)

onMounted(async () => {
  if (auth.isAuthenticated) {
    try {
      await auth.fetchMe()
    } catch {
      // если не удалось обновить — показываем то, что есть в store
    }
  }
})

async function onSave() {
  if (saving.value || !auth.user) return

  errors.value = {}
  const displayName = form.displayName.trim()
  const email = form.email.trim()

  if (!displayName) {
    errors.value.displayName = 'Введите имя'
  }
  if (!isValidEmail(email)) {
    errors.value.email = 'Введите корректный email'
  }
  if (Object.keys(errors.value).length > 0) return

  const payload: { displayName?: string; email?: string } = {}
  if (displayName !== auth.user.displayName) payload.displayName = displayName
  if (email !== auth.user.email) payload.email = email

  if (Object.keys(payload).length === 0) {
    notify({ title: 'Нет изменений', status: 'default' })
    return
  }

  saving.value = true
  try {
    const emailChanged = payload.email !== undefined
    await auth.updateProfile(payload)
    notify({
      title: emailChanged ? 'Проверьте новую почту' : 'Профиль сохранён',
      description: emailChanged
        ? 'Мы отправили письмо на новый адрес. Текущий email изменится после подтверждения.'
        : undefined,
      status: 'success',
    })
  } catch (error) {
    applyApiError(error, errors.value)
  } finally {
    saving.value = false
  }
}

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'home' })
}
</script>

<template>
  <div class="stub-page">
    <header class="stub-page__header">
      <h1 class="stub-page__title">Профиль</h1>
    </header>

    <template v-if="auth.user">
      <UiCard>
        <div class="profile-section">
          <h2 class="profile-section__title">Аккаунт</h2>

          <div class="profile-info">
            <p>
              <strong>Текущий email:</strong> {{ auth.user.email }}
              <span v-if="auth.user.emailVerified" class="profile-muted"> · подтверждён</span>
              <span v-else class="profile-muted"> · не подтверждён</span>
            </p>
            <p v-if="auth.user.pendingEmail" class="profile-pending">
              <strong>Ожидает подтверждения:</strong> {{ auth.user.pendingEmail }}
              <span class="profile-muted">
                — перейдите по ссылке из письма на новый адрес, чтобы сменить email. Текущий адрес
                останется активным до подтверждения.
              </span>
            </p>
          </div>

          <form class="profile-form" novalidate @submit.prevent="onSave">
            <div>
              <UiInput
                id="profile-display-name"
                v-model="form.displayName"
                type="text"
                placeholder="Как вас называть"
                autocomplete="name"
                :disabled="saving"
              >
                Имя
              </UiInput>
              <p v-if="errors.displayName" class="auth-form__error">{{ errors.displayName }}</p>
            </div>

            <div>
              <UiInput
                id="profile-email"
                v-model="form.email"
                type="email"
                placeholder="you@example.com"
                autocomplete="email"
                :disabled="saving"
              >
                Email
              </UiInput>
              <p v-if="errors.email" class="auth-form__error">{{ errors.email }}</p>
            </div>

            <div class="profile-actions">
              <UiButton type="submit" :disabled="saving">
                {{ saving ? 'Сохраняем…' : 'Сохранить изменения' }}
              </UiButton>
              <UiButton type="button" variant="ghost" @click="onLogout">Выйти</UiButton>
            </div>
          </form>
        </div>
      </UiCard>
    </template>

    <UiCard v-else>
      <p class="stub-hint">Данные пользователя не загружены — войдите заново.</p>
      <div class="profile-actions">
        <UiButton variant="ghost" @click="onLogout">На главную</UiButton>
      </div>
    </UiCard>
  </div>
</template>
