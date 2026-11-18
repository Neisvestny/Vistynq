import { request } from './http'
import type { User } from './types'

export interface UpdateProfilePayload {
  displayName?: string
  email?: string
}

export const profileApi = {
  /** Обновление профиля (имя/email). Email меняется после подтверждения через pendingEmail. */
  update(payload: UpdateProfilePayload): Promise<User> {
    return request<User>('/profile', { method: 'PATCH', body: payload })
  },

  /** Загрузка аватара (image ≤5 МБ). */
  uploadAvatar(file: File): Promise<{ avatarUrl: string }> {
    const form = new FormData()
    form.append('file', file)
    return request<{ avatarUrl: string }>('/profile/avatar', { method: 'POST', body: form })
  },

  /** Удаление аватара. */
  deleteAvatar(): Promise<void> {
    return request<void>('/profile/avatar', { method: 'DELETE' })
  },
}
