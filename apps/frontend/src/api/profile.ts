import { request, type UploadProgress } from './http'
import type { User } from './types'

export interface UpdateProfilePayload {
  displayName?: string
  email?: string
}

export const profileApi = {
  /** Obnovlenie profilya (imya/email). Email menyaetsya posle podtverzhdeniya cherez pendingEmail. */
  update(payload: UpdateProfilePayload): Promise<User> {
    return request<User>('/profile', { method: 'PATCH', body: payload })
  },

  /** Loading avatara (image ≤5 MB). */
  uploadAvatar(
    file: File,
    onUploadProgress?: (progress: UploadProgress) => void
  ): Promise<{ avatarUrl: string }> {
    const form = new FormData()
    form.append('file', file)
    return request<{ avatarUrl: string }>('/profile/avatar', {
      method: 'POST',
      body: form,
      onUploadProgress,
    })
  },

  /** Udalenie avatara. */
  deleteAvatar(): Promise<void> {
    return request<void>('/profile/avatar', { method: 'DELETE' })
  },
}
