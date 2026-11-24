<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import type { AccessEntry, InviteRequest } from '@/api/types'

const props = withDefaults(
  defineProps<{
    entries: AccessEntry[]
    loading?: boolean
    loadError?: string | null
    inviting?: boolean
    inviteErrors?: Record<string, string>
  }>(),
  {
    loading: false,
    loadError: null,
    inviting: false,
    inviteErrors: () => ({}),
  }
)

const emit = defineEmits<{
  invite: [payload: InviteRequest]
  'change-role': [entry: AccessEntry, role: 'viewer' | 'editor']
  revoke: [entry: AccessEntry]
  reload: []
}>()

const { t } = useI18n()
const email = ref('')
const role = ref<'viewer' | 'editor'>('viewer')
const confirmingRevoke = ref<string | null>(null)

function revokeKey(entry: AccessEntry): string {
  return entry.invitationId ?? entry.userId ?? entry.email
}

function requestRevoke(entry: AccessEntry): void {
  confirmingRevoke.value = revokeKey(entry)
}

function confirmRevoke(entry: AccessEntry): void {
  emit('revoke', entry)
  confirmingRevoke.value = null
}

const sortedEntries = computed(() => {
  return [...props.entries].sort((a, b) => {
    if (a.pending !== b.pending) return a.pending ? 1 : -1
    return a.email.localeCompare(b.email, 'ru')
  })
})

function onInvite(): void {
  const value = email.value.trim()
  if (!value) return
  emit('invite', { email: value, role: role.value })
}

function onInviteSuccess(): void {
  email.value = ''
  role.value = 'viewer'
}

defineExpose({ onInviteSuccess })
</script>

<template>
  <section class="access-panel">
    <div class="access-panel__section">
      <h3 class="access-panel__title">{{ t('access.usersTitle') }}</h3>

      <form class="access-panel__invite" @submit.prevent="onInvite">
        <div class="access-panel__invite-row">
          <div class="access-panel__invite-email">
            <UiInput
              id="access-invite-email"
              v-model="email"
              type="email"
              placeholder="email@example.com"
              autocomplete="email"
              :disabled="inviting"
            >
              {{ t('common.email') }}
            </UiInput>
            <p v-if="inviteErrors.email" class="access-panel__error">{{ inviteErrors.email }}</p>
          </div>

          <label class="access-panel__role">
            <span class="access-panel__label">{{ t('access.roleLabel') }}</span>
            <select v-model="role" class="access-panel__select" :disabled="inviting">
              <option value="viewer">{{ t('common.viewer') }}</option>
              <option value="editor">{{ t('common.editor') }}</option>
            </select>
            <p v-if="inviteErrors.role" class="access-panel__error">{{ inviteErrors.role }}</p>
          </label>

          <UiButton type="submit" size="sm" :disabled="inviting || !email.trim()">
            {{ inviting ? t('access.inviting') : t('access.grant') }}
          </UiButton>
        </div>
      </form>
    </div>

    <div class="access-panel__section">
      <div class="access-panel__list-header">
        <h4 class="access-panel__subtitle">{{ t('access.accesses') }}</h4>
        <UiButton v-if="loadError" variant="ghost" size="sm" type="button" @click="emit('reload')">
          {{ t('common.retry') }}
        </UiButton>
      </div>

      <p v-if="loading" class="access-panel__muted">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="access-panel__error">{{ loadError }}</p>
      <p v-else-if="sortedEntries.length === 0" class="access-panel__muted">
        {{ t('access.noAccess') }}
      </p>

      <ul v-else class="access-panel__list">
        <li
          v-for="entry in sortedEntries"
          :key="revokeKey(entry)"
          class="access-panel__item"
        >
          <div class="access-panel__item-main">
            <div class="access-panel__item-email">{{ entry.email }}</div>
            <div class="access-panel__item-meta">
              <UiBadge v-if="entry.pending" tone="neutral">{{
                t('access.pendingRegistration')
              }}</UiBadge>
              <span v-else class="access-panel__muted">{{ entry.role }}</span>
            </div>
          </div>

          <div class="access-panel__item-actions">
            <label v-if="!entry.pending && entry.userId" class="access-panel__inline-role">
              <span class="sr-only">{{ t('access.roleFor', { email: entry.email }) }}</span>
              <select
                class="access-panel__select access-panel__select--sm"
                :value="entry.role"
                @change="
                  emit(
                    'change-role',
                    entry,
                    ($event.target as HTMLSelectElement).value as 'viewer' | 'editor'
                  )
                "
              >
                <option value="viewer">viewer</option>
                <option value="editor">editor</option>
              </select>
            </label>

            <div class="access-panel__revoke">
              <template v-if="confirmingRevoke === revokeKey(entry)">
                <p class="access-panel__confirm" role="alert">
                  {{ entry.pending ? t('access.revokeInviteConfirm') : t('access.revokeConfirm') }}
                </p>
                <UiButton
                  variant="danger"
                  size="sm"
                  type="button"
                  @click="confirmRevoke(entry)"
                >
                  {{ t('access.revoke') }}
                </UiButton>
                <UiButton
                  variant="ghost"
                  size="sm"
                  type="button"
                  @click="confirmingRevoke = null"
                >
                  {{ t('common.cancel') }}
                </UiButton>
              </template>
              <UiButton
                v-else
                variant="ghost"
                size="sm"
                type="button"
                :aria-label="
                  entry.pending
                    ? t('access.revokeInvite', { email: entry.email })
                    : t('access.revokeAccess', { email: entry.email })
                "
                @click="requestRevoke(entry)"
              >
                {{ t('access.revoke') }}
              </UiButton>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.access-panel {
  display: grid;
  gap: 1rem;
}
.access-panel__section {
  display: grid;
  gap: 0.75rem;
}
.access-panel__title,
.access-panel__subtitle {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}
.access-panel__invite-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 140px auto;
  gap: 0.75rem;
  align-items: end;
}
.access-panel__invite-email,
.access-panel__role {
  display: grid;
  gap: 0.25rem;
}
.access-panel__label {
  font-size: 0.85rem;
  font-weight: 500;
}
.access-panel__select {
  width: 100%;
  min-height: 40px;
  padding: 0 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}
.access-panel__select--sm {
  min-height: 34px;
  padding: 0 0.5rem;
  font-size: 0.85rem;
}
.access-panel__list-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.access-panel__muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
.access-panel__error {
  margin: 0;
  color: var(--color-danger, #b42318);
  font-size: 0.8rem;
}
.access-panel__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}
.access-panel__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.access-panel__item-main {
  min-width: 0;
  display: grid;
  gap: 0.2rem;
}
.access-panel__item-email {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.9rem;
}
.access-panel__item-meta {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.access-panel__item-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.access-panel__inline-role {
  width: 110px;
}
.access-panel__revoke {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.access-panel__confirm {
  flex-basis: 100%;
  margin: 0;
  color: var(--color-danger);
  font-size: var(--font-size-sm);
  line-height: var(--line-height-base);
}
@media (max-width: 720px) {
  .access-panel__invite-row {
    grid-template-columns: 1fr;
  }
  .access-panel__item {
    flex-direction: column;
    align-items: stretch;
  }
  .access-panel__item-actions {
    justify-content: space-between;
  }
}
</style>
