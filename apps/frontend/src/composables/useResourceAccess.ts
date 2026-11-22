import { ref } from 'vue'
import { tGlobal } from '@/i18n'
import { ApiError } from '@/api/http'
import { notify } from '@/api/notify'
import type { AccessEntry, InviteRequest, InviteResult, RoleRequest } from '@/api/types'

export interface ResourceAccessAdapter {
  list: () => Promise<AccessEntry[]>
  invite: (payload: InviteRequest) => Promise<InviteResult>
  updateRole: (userId: string, payload: RoleRequest) => Promise<void>
  revokeAccess: (userId: string) => Promise<void>
  revokeInvitation: (invitationId: string) => Promise<void>
}

export function useResourceAccess(adapter: () => ResourceAccessAdapter) {
  const entries = ref<AccessEntry[]>([])
  const loading = ref(false)
  const inviting = ref(false)
  const loadError = ref<string | null>(null)
  const inviteErrors = ref<Record<string, string>>({})

  async function load(): Promise<void> {
    loading.value = true
    loadError.value = null
    try {
      entries.value = await adapter().list()
    } catch (error) {
      loadError.value = error instanceof ApiError ? error.message : tGlobal('access.loadError')
    } finally {
      loading.value = false
    }
  }

  async function invite(payload: InviteRequest): Promise<InviteResult | null> {
    inviting.value = true
    inviteErrors.value = {}
    try {
      const result = await adapter().invite(payload)
      if (result.status === 'granted') {
        notify({ title: tGlobal('access.granted'), status: 'success' })
      } else {
        notify({
          title: tGlobal('access.inviteSent'),
          description: tGlobal('access.inviteSentDescription'),
          status: 'success',
        })
      }
      await load()
      return result
    } catch (error) {
      inviteErrors.value = {}
      if (error instanceof ApiError) {
        if (error.details) {
          for (const [field, messages] of Object.entries(error.details)) {
            inviteErrors.value[field] = messages[0] ?? tGlobal('validation.invalidValue')
          }
        }
        if (!inviteErrors.value.email && !inviteErrors.value.role) {
          notify({
            title: tGlobal('access.grantError'),
            description: error.message,
            status: 'error',
          })
        }
      } else {
        notify({ title: tGlobal('access.grantError'), status: 'error' })
      }
      return null
    } finally {
      inviting.value = false
    }
  }

  async function changeRole(entry: AccessEntry, role: 'viewer' | 'editor'): Promise<boolean> {
    if (!entry.userId || entry.pending || entry.role === role) return false
    try {
      await adapter().updateRole(entry.userId, { role })
      notify({ title: tGlobal('access.roleUpdated'), status: 'success' })
      await load()
      return true
    } catch (error) {
      notify({
        title: tGlobal('access.roleUpdateError'),
        description: error instanceof ApiError ? error.message : undefined,
        status: 'error',
      })
      return false
    }
  }

  async function revoke(entry: AccessEntry): Promise<boolean> {
    try {
      if (entry.pending) {
        if (!entry.invitationId) return false
        await adapter().revokeInvitation(entry.invitationId)
        notify({ title: tGlobal('access.inviteRevoked'), status: 'success' })
      } else {
        if (!entry.userId) return false
        await adapter().revokeAccess(entry.userId)
        notify({ title: tGlobal('access.accessRevoked'), status: 'success' })
      }
      await load()
      return true
    } catch (error) {
      notify({
        title: tGlobal('access.revokeError'),
        description: error instanceof ApiError ? error.message : undefined,
        status: 'error',
      })
      return false
    }
  }

  return {
    entries,
    loading,
    inviting,
    loadError,
    inviteErrors,
    load,
    invite,
    changeRole,
    revoke,
  }
}
