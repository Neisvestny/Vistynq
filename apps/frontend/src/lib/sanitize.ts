import DOMPurify from 'dompurify'

/**
 * Санитизация HTML перед `v-html` (R18).
 *
 * Сервер уже отдаёт `bodyHtml` после escape-first рендера, но клиент не
 * должен доверять HTML из сети: Tiptap (R28) и публичные /shared-страницы
 * (R32) обязаны прогонять контент через эту функцию.
 *
 * Whitelist минимальный — только то, что реально умеет Tiptap-редактор.
 * Картинки/аудио появятся вместе с вложениями в R31 и будут добавлены сюда
 * отдельными тегами/атрибутами.
 */
const ALLOWED_TAGS = [
  'p',
  'br',
  'strong',
  'em',
  'u',
  's',
  'del',
  'code',
  'pre',
  'blockquote',
  'ul',
  'ol',
  'li',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'a',
  'hr',
]

const ALLOWED_ATTR = ['href', 'title', 'target', 'rel']

/** http(s), локальные пути (`/shared/...`, `/storage/...`) и якоря. */
const ALLOWED_URI = /^(?:https?:\/\/|\/(?!\/)|#)/i

export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP: ALLOWED_URI,
    FORBID_ATTR: ['style'],
  })
}
