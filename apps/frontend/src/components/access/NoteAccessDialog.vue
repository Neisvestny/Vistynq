<script setup lang="ts">
import { ref, watch } from 'vue'
import { accessApi } from '@/api/access'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import type { InviteRequest, NoteVisibility } from '@/api/types'
import UiDialog from '@/components/ui/UiDialog.vue'
import ResourceAccessPanel from '@/components/access/ResourceAccessPanel.vue'
import { useResourceAccess } from '@/composables/useResourceAccess'

const props = defineProps<{
  open: boolean
  noteId: string
  visibility: NoteVisibility
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'visibility-changed': [visibility: NoteVisibility]
}>()

const panelRef = ref<InstanceType<typeof ResourceAccessPanel> | null>(null)
const visibility = ref<NoteVisibility>(props.visibility)
const visibilitySaving = ref(false)

const access = useResourceAccess(() => ({
  list: () => accessApi.listNoteAccess(props.noteId),
  invite: (payload) => accessApi.inviteToNote(props.noteId, payload),
  updateRole: (userId, payload) => accessApi.updateNoteRole(props.noteId, userId, payload),
  revokeAccess: (userId) => accessApi.revokeNoteAccess(props.noteId, userId),
  revokeInvitation: (invitationId) => accessApi.revokeNoteInvitation(props.noteId, invitationId),
}))

watch(
  () => props.open,
  (open) => {
    if (!open) return
    visibility.value = props.visibility
    void access.load()
  }
)

watch(
  () => props.visibility,
  (next) => {
    if (!props.open) visibility.value = next
  }
)

async function setVisibility(next: NoteVisibility): Promise<void> {
  if (next === visibility.value || visibilitySaving.value) return
  visibilitySaving.value = true
  try {
    const result = await accessApi.setNoteVisibility(props.noteId, next)
    visibility.value = result.visibility
    emit('visibility-changed', result.visibility)
    notify({
      title: result.visibility === 'public' ? 'Заметка стала публичной' : 'Заметка стала приватной',
      status: 'success',
    })
  } catch (error) {
    notify({
      title: 'Не удалось изменить видимость',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    visibilitySaving.value = false
  }
}

async function onInvite(payload: InviteRequest): Promise<void> {
  const result = await access.invite(payload)
  if (result) panelRef.value?.onInviteSuccess()
}
</script>

<template>
  <UiDialog :open="open" title="Доступ к заметке" @update:open="emit('update:open', $event)">
    <div class="access-dialog">
      <section class="access-dialog__section">
        <h3 class="access-dialog__title">Видимость</h3>
        <div class="access-dialog__segment" role="radiogroup" aria-label="Видимость заметки">
          <button
            type="button"
            class="access-dialog__segment-btn"
            :class="{ 'access-dialog__segment-btn--active': visibility === 'public' }"
            :aria-pressed="visibility === 'public'"
            :disabled="visibilitySaving"
            @click="setVisibility('public')"
          >
            Публичная (по правам канваса)
          </button>
          <button
            type="button"
            class="access-dialog__segment-btn"
            :class="{ 'access-dialog__segment-btn--active': visibility === 'private' }"
            :aria-pressed="visibility === 'private'"
            :disabled="visibilitySaving"
            @click="setVisibility('private')"
          >
            Приватная
          </button>
        </div>
        <p class="access-dialog__hint">
          {{
            visibility === 'private'
              ? 'Приватная заметка видна только автору, владельцу канваса и пользователям, которым выдан доступ.'
              : 'Публичная заметка видна всем, у кого есть доступ к канвасу.'
          }}
        </p>
      </section>

      <hr class="access-dialog__divider" />

      <ResourceAccessPanel
        ref="panelRef"
        :entries="access.entries.value"
        :loading="access.loading.value"
        :load-error="access.loadError.value"
        :inviting="access.inviting.value"
        :invite-errors="access.inviteErrors.value"
        @invite="onInvite"
        @change-role="access.changeRole"
        @revoke="access.revoke"
        @reload="access.load"
      />
    </div>
  </UiDialog>
</template>

<style scoped>
.access-dialog {
  display: grid;
  gap: 1rem;
  margin-top: 1rem;
}
.access-dialog__section {
  display: grid;
  gap: 0.75rem;
}
.access-dialog__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}
.access-dialog__segment {
  display: inline-flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.access-dialog__segment-btn {
  padding: 0.5rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.875rem;
  cursor: pointer;
}
.access-dialog__segment-btn--active {
  border-color: var(--color-accent);
  background: var(--color-accent-soft, rgba(0, 0, 0, 0.04));
  color: var(--color-accent);
}
.access-dialog__segment-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.access-dialog__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}
.access-dialog__divider {
  border: 0;
  border-top: 1px solid var(--color-border);
  margin: 0;
}
</style>
