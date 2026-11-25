<script setup lang="ts">
/** Profil: imya, email, pendingEmail, avatar. */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/http'
import { profileApi } from '@/api/profile'
import { applyApiError, isValidEmail } from '@/api/errors'
import { notify } from '@/api/notify'
import { focusFirstInvalidField } from '@/lib/formFeedback'
import { matchesImageMime } from '@/lib/fileType'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  displayName: '',
  email: '',
})

const saving = ref(false)
const errors = ref<Record<string, string>>({})
const formError = ref('')
const avatarUploading = ref(false)
const avatarProgress = ref(0)
const avatarDeleting = ref(false)
const confirmDeleteAvatar = ref(false)
const avatarInputRef = ref<HTMLInputElement | null>(null)
let avatarRequest = 0

const AVATAR_MAX_BYTES = 5 * 1024 * 1024
const AVATAR_ACCEPT = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']

const avatarUrl = computed(() => auth.user?.avatarUrl ?? null)
const avatarInitial = computed(() => (auth.user?.displayName?.trim()?.[0] ?? 'U').toUpperCase())

function syncForm() {
  if (saving.value) return
  form.displayName = auth.user?.displayName ?? ''
  form.email = auth.user?.email ?? ''
}

onMounted(async () => {
  await auth.ensureRestored()
  if (!auth.isAuthenticated) {
    await router.replace({ name: 'login' })
    return
  }
  syncForm()
})

watch(() => auth.user, syncForm)

function pickAvatar() {
  avatarInputRef.value?.click()
}

async function onAvatarChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  const request = ++avatarRequest
  if (!AVATAR_ACCEPT.includes(file.type) || !(await matchesImageMime(file, file.type))) {
    notify({ title: t('profile.avatarInvalidType'), status: 'error' })
    return
  }
  if (file.size > AVATAR_MAX_BYTES) {
    notify({ title: t('profile.avatarTooLarge'), status: 'error' })
    return
  }

  avatarUploading.value = true
  avatarProgress.value = 0
  try {
    const response = await profileApi.uploadAvatar(file, (progress) => {
      if (request === avatarRequest) avatarProgress.value = progress.percent
    })
    if (request !== avatarRequest) return
    auth.setAvatarUrl(response.avatarUrl)
    notify({ title: t('profile.avatarUpdated'), status: 'success' })
  } catch (error) {
    if (request !== avatarRequest) return
    notify({
      title: t('profile.avatarUploadError'),
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    if (request === avatarRequest) {
      avatarUploading.value = false
      avatarProgress.value = 0
    }
  }
}

async function onDeleteAvatar() {
  if (avatarDeleting.value || !avatarUrl.value) return

  avatarDeleting.value = true
  avatarRequest += 1
  try {
    await profileApi.deleteAvatar()
    auth.setAvatarUrl(null)
    confirmDeleteAvatar.value = false
    notify({ title: t('profile.avatarDeleted'), status: 'success' })
  } catch (error) {
    notify({
      title: t('profile.avatarDeleteError'),
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    avatarDeleting.value = false
  }
}

function validate(): boolean {
  errors.value = {}
  formError.value = ''

  const displayName = form.displayName.trim()
  const email = form.email.trim().toLowerCase()

  if (!displayName) {
    errors.value.displayName = t('profile.nameRequired')
  }

  if (!email) {
    errors.value.email = t('profile.emailRequired')
  } else if (!isValidEmail(email)) {
    errors.value.email = t('profile.emailInvalid')
  }

  if (Object.keys(errors.value).length > 0) {
    void focusFirstInvalidField()
    return false
  }

  return true
}

async function onSubmit() {
  if (saving.value) return
  if (!validate()) return

  const payload: { displayName?: string; email?: string } = {}
  if (form.displayName.trim() !== (auth.user?.displayName ?? '')) {
    payload.displayName = form.displayName.trim()
  }
  if (form.email.trim().toLowerCase() !== (auth.user?.email ?? '')) {
    payload.email = form.email.trim().toLowerCase()
  }

  if (Object.keys(payload).length === 0) {
    notify({ title: t('common.noChanges'), status: 'default' })
    return
  }

  saving.value = true
  const emailChanged = payload.email !== undefined
  try {
    await auth.updateProfile(payload)
    notify({
      title: emailChanged ? t('auth.verifyEmailPending') : t('profile.profileSaved'),
      description: emailChanged
        ? t('profile.pendingEmail', { email: auth.user?.pendingEmail ?? payload.email ?? '' })
        : undefined,
      status: 'success',
    })
  } catch (error) {
    formError.value = applyApiError(error, errors.value)
    void focusFirstInvalidField()
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
  <div class="container profile-page">
    <UiCard class="profile-page__card">
      <div class="profile-page__header">
        <div>
          <h1 class="profile-page__title">{{ t('profile.title') }}</h1>
          <p class="profile-page__subtitle">{{ t('profile.subtitle') }}</p>
        </div>
        <UiButton variant="ghost" size="sm" type="button" @click="onLogout">
          {{ t('nav.logout') }}
        </UiButton>
      </div>

      <div class="profile-page__section">
        <h2 class="profile-page__section-title">{{ t('profile.language') }}</h2>
        <p class="profile-page__section-description">{{ t('profile.languageDescription') }}</p>
        <LanguageSwitcher />
      </div>

      <div class="profile-page__section">
        <h2 class="profile-page__section-title">{{ t('profile.avatar') }}</h2>
        <div class="profile-page__avatar-row">
          <div class="profile-page__avatar" role="img" :aria-label="t('profile.avatarAlt')">
            <img v-if="avatarUrl" :src="avatarUrl" :alt="t('profile.avatarAlt')" />
            <span v-else aria-hidden="true">{{ avatarInitial }}</span>
          </div>

          <div class="profile-page__avatar-actions">
            <p class="profile-page__hint">{{ t('profile.avatarFormats') }}</p>
            <div class="profile-page__avatar-buttons">
              <UiButton variant="ghost" size="sm" :disabled="avatarUploading" @click="pickAvatar">
                {{ avatarUploading ? t('common.uploading') : t('profile.changeAvatar') }}
              </UiButton>
              <UiButton
                v-if="avatarUrl && !confirmDeleteAvatar"
                variant="ghost"
                size="sm"
                :disabled="avatarDeleting"
                @click="confirmDeleteAvatar = true"
              >
                {{ t('profile.deleteAvatar') }}
              </UiButton>
            </div>

            <div v-if="confirmDeleteAvatar" class="profile-page__confirm" role="group">
              <p>{{ t('profile.avatarDeleteConfirm') }}</p>
              <div class="profile-page__avatar-buttons">
                <UiButton
                  variant="danger"
                  size="sm"
                  :disabled="avatarDeleting"
                  @click="onDeleteAvatar"
                >
                  {{ avatarDeleting ? t('common.deleting') : t('profile.deleteAvatar') }}
                </UiButton>
                <UiButton
                  variant="ghost"
                  size="sm"
                  :disabled="avatarDeleting"
                  @click="confirmDeleteAvatar = false"
                >
                  {{ t('common.cancel') }}
                </UiButton>
              </div>
            </div>

            <p v-if="avatarUploading" class="profile-page__hint" role="status">
              {{ t('common.uploading') }} — {{ avatarProgress }}%
            </p>
            <input
              ref="avatarInputRef"
              type="file"
              accept="image/png,image/jpeg,image/gif,image/webp"
              class="sr-only"
              tabindex="-1"
              @change="onAvatarChange"
            />
          </div>
        </div>
      </div>

      <form class="profile-page__form" novalidate @submit.prevent="onSubmit">
        <h2 class="profile-page__section-title">{{ t('profile.details') }}</h2>

        <div v-if="formError" class="auth-form__error-banner" role="alert">
          <span>{{ formError }}</span>
          <button type="button" class="auth-form__link-button" @click="onSubmit">
            {{ t('common.retry') }}
          </button>
        </div>

        <UiInput
          id="profile-display-name"
          v-model="form.displayName"
          autocomplete="name"
          :error="errors.displayName"
        >
          {{ t('profile.displayName') }}
        </UiInput>

        <div>
          <UiInput
            id="profile-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            :error="errors.email"
          >
            {{ t('profile.email') }}
          </UiInput>
          <p v-if="auth.user?.pendingEmail" class="profile-page__hint">
            {{ t('profile.pendingEmail', { email: auth.user.pendingEmail }) }}
          </p>
        </div>

        <div class="profile-page__actions">
          <UiButton type="submit" :disabled="saving">
            {{ saving ? t('common.saving') : t('profile.saveProfile') }}
          </UiButton>
        </div>
      </form>
    </UiCard>
  </div>
</template>
