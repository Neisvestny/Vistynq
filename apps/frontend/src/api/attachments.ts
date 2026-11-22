import { request, type UploadProgress } from './http'
import type { Attachment, AttachmentUrlResult } from './types'

export const attachmentsApi = {
  /** Zagruzit fayl k zametke (redaktor zametki). */
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

  /** Poluchit presigned URL fayla. */
  getUrl(id: string): Promise<AttachmentUrlResult> {
    return request<AttachmentUrlResult>(`/attachments/${id}/url`)
  },

  /** Udalit fayl (redaktor zametki). */
  remove(id: string): Promise<void> {
    return request<void>(`/attachments/${id}`, { method: 'DELETE' })
  },
}
