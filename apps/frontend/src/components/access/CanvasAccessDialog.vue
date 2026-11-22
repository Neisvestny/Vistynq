<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { accessApi } from '@/api/access'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import type { AccessLevel, InviteRequest } from '@/api/types'
import UiButton from '@/components/ui/UiButton.vue'
import UiDialog from '@/components/ui/UiDialog.vue'
import ResourceAccessPanel from '@/components/access/ResourceAccessPanel.vue'
import { useResourceAccess } from '@/composables/useResourceAccess'
import { copyText } from '@/lib/clipboard'

const props = defineProps<{
  open: boolean
  canvasId: string
  accessLevel: AccessLevel
  publicSlug: string | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'access-level-changed': [payload: { level: AccessLevel; publicSlug: string | null }]
}>()

const panelRef = ref<InstanceType<typeof ResourceAccessPanel> | null>(null)
const level = ref<AccessLevel>(props.accessLevel)
const publicSlug = ref<string | null>(props.publicSlug)
const levelSaving = ref(false)

const access = useResourceAccess(() => ({
  list: () => accessApi.listCanvasAccess(props.canvasId),
  invite: (payload) => accessApi.inviteToCanvas(props.canvasId, payload),
  updateRole: (userId, payload) => accessApi.updateCanvasRole(props.canvasId, userId, payload),
  revokeAccess: (userId) => accessApi.revokeCanvasAccess(props.canvasId, userId),
  revokeInvitation: (invitationId) =>
    accessApi.revokeCanvasInvitation(props.canvasId, invitationId),
}))

const shareUrl = computed(() => {
  if (!publicSlug.value) return null
  return `${window.location.origin}/shared/canvas/${publicSlug.value}`
})

watch(
  () => props.open,
  (open) => {
    if (!open) return
    level.value = props.accessLevel
    publicSlug.value = props.publicSlug
    void access.load()
  }
)

watch(
  () => [props.accessLevel, props.publicSlug] as const,
  ([nextLevel, nextSlug]) => {
    if (!props.open) {
      level.value = nextLevel
      publicSlug.value = nextSlug
    }
  }
)

async function setLevel(next: AccessLevel): Promise<void> {
  if (next === level.value || levelSaving.value) return
  levelSaving.value = true
  try {
    const result = await accessApi.setCanvasAccessLevel(props.canvasId, next)
    level.value = result.level
    publicSlug.value = result.publicSlug
    emit('access-level-changed', result)
    notify({
      title: result.level === 'public' ? 'Публичная ссылка включена' : 'Публичная ссылка выключена',
      status: 'success',
    })
  } catch (error) {
    notify({
      title: 'Не удалось изменить доступ канваса',
      description: error instanceof ApiError ? error.message : undefined,
      status: 'error',
    })
  } finally {
    levelSaving.value = false
  }
}

async function onInvite(payload: InviteRequest): Promise<void> {
  const result = await access.invite(payload)
  if (result) panelRef.value?.onInviteSuccess()
}

async function onCopy(): Promise<void> {
  if (!shareUrl.value) return
  await copyText(shareUrl.value, 'Ссылка скопирована')
}
</script>

<template>
  <UiDialog :open="open" title="Доступ к канвасу" @update:open="emit('update:open', $event)">
    <div class="access-dialog">
      <section class="access-dialog__section">
        <h3 class="access-dialog__title">Уровень доступа</h3>
        <div class="access-dialog__segment" role="radiogroup" aria-label="Уровень доступа канваса">
          <button
            type="button"
            class="access-dialog__segment-btn"
            :class="{ 'access-dialog__segment-btn--active': level === 'private' }"
            :aria-pressed="level === 'private'"
            :disabled="levelSaving"
            @click="setLevel('private')"
          >
            Только я
          </button>
          <button
            type="button"
            class="access-dialog__segment-btn"
            :class="{ 'access-dialog__segment-btn--active': level === 'public' }"
            :aria-pressed="level === 'public'"
            :disabled="levelSaving"
            @click="setLevel('public')"
          >
            Публичная ссылка
          </button>
        </div>

        <div v-if="level === 'public' && shareUrl" class="access-dialog__share">
          <label class="access-dialog__share-label" for="canvas-share-url"
            >Ссылка для просмотра</label
          >
          <div class="access-dialog__share-row">
            <input
              id="canvas-share-url"
              class="access-dialog__share-input"
              :value="shareUrl"
              readonly
            />
            <UiButton type="button" variant="ghost" size="sm" @click="onCopy">Копировать</UiButton>
          </div>
          <p class="access-dialog__hint">
            Любой, у кого есть ссылка, сможет смотреть публичные заметки канваса.
          </p>
        </div>
        <p v-else class="access-dialog__hint">
          Канвас доступен только вам и пользователям, которым выдали доступ.
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
.access-dialog__share {
  display: grid;
  gap: 0.35rem;
}
.access-dialog__share-label {
  font-size: 0.85rem;
  font-weight: 500;
}
.access-dialog__share-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.access-dialog__share-input {
  flex: 1;
  min-width: 0;
  min-height: 40px;
  padding: 0 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface-alt, var(--color-surface));
  color: var(--color-text);
  font: inherit;
  font-size: 0.875rem;
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
