<script setup lang="ts">
/** Профиль: имя, email, pendingEmail, аватар. */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/http'
import { profileApi } from '@/api/profile'
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
const avatarUploading = ref(false)
const avatarProgress = ref(0)
const avatarDeleting = ref(false)
const avatarInputRef = ref<HTMLInputElement | null>(null)

const AVATAR_MAX_BYTES = 5 * 1024 * 1024
const AVATAR_ACCEPT = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']

const avatarUrl = computed(() => auth.user?.avatarUrl ?? null)
const avatarInitial = computed(() => (auth.user?.displayName?.trim()?.[0] ?? 'U').toUpperCase())

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

function pickAvatar(): void {
  if (avatarUploading.value || avatarDeleting.value) return
  avatarInputRef.value?.click()
}

async function onAvatarChange(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]
  if (input) input.value = ''
  if (!file) return

  if (!AVATAR_ACCEPT.includes(file.type) && !/\.(png|jpe?g|gif|webp)$/i.test(file.name)) {
    notify({
      title: 'Неподдерживаемый формат',
      description: 'Выберите изображение PNG, JPG, GIF или WebP',
      status: 'error',
    })
    return
  }
  if (file.size > AVATAR_MAX_BYTES) {
    notify({
      title: 'Файл слишком большой',
      description: 'Аватар должен быть не больше 5 МБ',
      status: 'error',
    })
    return
  }

  avatarUploading.value = true
  avatarProgress.value = 0
  try {
    const result = await profileApi.uploadAvatar(file, (p) => {
      avatarProgress.value = p.percent
    })
    auth.setAvatarUrl(result.avatarUrl)
    notify({ title: 'Аватар обновлён', status: 'success' })
  } catch (error) {
    notify({
      title: 'Не удалось загрузить аватар',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    avatarUploading.value = false
    avatarProgress.value = 0
  }
}

async function onDeleteAvatar(): Promise<void> {
  if (avatarDeleting.value || avatarUploading.value || !avatarUrl.value) return
  avatarDeleting.value = true
  try {
    await profileApi.deleteAvatar()
    auth.setAvatarUrl(null)
    notify({ title: 'Аватар удалён', status: 'success' })
  } catch (error) {
    notify({
      title: 'Не удалось удалить аватар',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    avatarDeleting.value = false
  }
}

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

          <div class="profile-avatar">
            <div
              class="profile-avatar__preview"
              :aria-label="avatarUrl ? 'Аватар пользователя' : 'Аватар не задан'"
            >
              <img v-if="avatarUrl" :src="avatarUrl" alt="Аватар" class="profile-avatar__image" />
              <span v-else class="profile-avatar__initial">{{ avatarInitial }}</span>
            </div>

            <div class="profile-avatar__controls">
              <p class="profile-avatar__hint">PNG, JPG, GIF или WebP, до 5 МБ</p>
              <div class="profile-avatar__actions">
                <UiButton
                  type="button"
                  variant="ghost"
                  size="sm"
                  :disabled="avatarUploading || avatarDeleting"
                  @click="pickAvatar"
                >
                  {{
                    avatarUploading
                      ? `Загрузка… ${avatarProgress}%`
                      : avatarUrl
                        ? 'Заменить аватар'
                        : 'Загрузить аватар'
                  }}
                </UiButton>
                <UiButton
                  v-if="avatarUrl"
                  type="button"
                  variant="ghost"
                  size="sm"
                  :disabled="avatarUploading || avatarDeleting"
                  @click="onDeleteAvatar"
                >
                  {{ avatarDeleting ? 'Удаление…' : 'Удалить' }}
                </UiButton>
              </div>
              <div
                v-if="avatarUploading"
                class="profile-avatar__progress"
                role="progressbar"
                :aria-valuenow="avatarProgress"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div
                  class="profile-avatar__progress-bar"
                  :style="{ width: `${avatarProgress}%` }"
                />
              </div>
              <input
                ref="avatarInputRef"
                type="file"
                class="profile-avatar__input"
                accept="image/png,image/jpeg,image/gif,image/webp"
                @change="onAvatarChange"
              />
            </div>
          </div>

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
