import { request, type UploadProgress } from './http'
import type { Attachment, AttachmentUrlResult } from './types'

export const attachmentsApi = {
  /** Загрузить файл к заметке (редактор заметки). */
  upload(
    noteId: string,
    file: File,
    onUploadProgress?: (progress: UploadProgress) => void
  ): Promise<Attachment> {
    const form = new FormData()
    form.append('file', file)
    return request<Attachment>(`/notes/${noteId}/attachments`, {
      method: 'POST',
      body: form,
      onUploadProgress,
    })
  },

  /** Получить presigned URL файла. */
  getUrl(id: string): Promise<AttachmentUrlResult> {
    return request<AttachmentUrlResult>(`/attachments/${id}/url`)
  },

  /** Удалить файл (редактор заметки). */
  remove(id: string): Promise<void> {
    return request<void>(`/attachments/${id}`, { method: 'DELETE' })
  },
}
