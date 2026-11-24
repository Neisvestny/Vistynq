import DOMPurify from 'dompurify'

/**
 * Sanitizatsiya HTML pered `v-html` (R18/R32).
 *
 * Server uzhe otdayot `bodyHtml` posle escape-first rendera, no klient ne
 * dolzhen doveryat HTML iz seti: Tiptap (R28) i publichnye /shared-stranitsy
 * (R32) obyazany progonyat kontent cherez etu funktsiyu.
 *
 * Whitelist minimalnyy i bezopasnyy. Media dopuskayutsya tolko s bezopasnymi
 * URI (http/https, otnositelnye puti, yakorya).
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
  'img',
]

const ALLOWED_ATTR = ['href', 'title', 'target', 'rel', 'src', 'alt', 'loading', 'width', 'height']

/** http(s), mailto, lokalnye puti (`/shared/...`, `/storage/...`) i yakorya. */
const ALLOWED_URI = /^(?:https?:\/\/|mailto:|\/(?!\/)|#)/i

/**
 * Vneshniye ssylki vsega otkryvayutsya cherez `target="_blank"` — obyazatelno
 * vystavlyaem bezopasnyy rel, dazhe esli avtor zapisi ukazal svoy.
 */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (typeof HTMLAnchorElement === 'undefined' || !(node instanceof HTMLAnchorElement)) return
  const href = node.getAttribute('href') ?? ''
  if (!/^https?:\/\//i.test(href)) return
  node.setAttribute('target', '_blank')
  node.setAttribute('rel', 'noopener noreferrer nofollow')
})

export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP: ALLOWED_URI,
    FORBID_ATTR: ['style'],
  })
}
