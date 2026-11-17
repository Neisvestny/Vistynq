// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { sanitizeHtml } from '../sanitize'

/**
 * R18: клиентский санитайзер для будущего Tiptap (R28) и /shared (R32).
 * Проверяем, что опасный HTML/URL не выживают, а разрешённая разметка —
 * сохраняется.
 */
describe('sanitizeHtml', () => {
  it('removes scripts and event handlers', () => {
    const html = sanitizeHtml('<p onclick="alert(1)">ok</p><script>alert(2)</script>')

    expect(html).toContain('<p>ok</p>')
    expect(html).not.toContain('<script')
    expect(html).not.toContain('onclick')
  })

  it('removes images with onerror', () => {
    const html = sanitizeHtml('<img src=x onerror="alert(1)">')

    expect(html).not.toContain('<img')
    expect(html).not.toContain('onerror')
  })

  it('drops javascript and data URLs', () => {
    const html = sanitizeHtml(
      '<a href="javascript:alert(1)">js</a><a href="data:text/html,<script>alert(1)</script>">data</a>'
    )

    expect(html).not.toContain('javascript:')
    expect(html).not.toContain('data:')
    expect(html).not.toContain('<script')
  })

  it('drops protocol-relative URLs', () => {
    const html = sanitizeHtml('<a href="//evil.example">x</a>')

    expect(html).not.toContain('//evil.example')
  })

  it('keeps whitelisted markup and safe links', () => {
    const html = sanitizeHtml(
      '<h1>Заголовок</h1><p><strong>bold</strong> <em>it</em> <code>x &lt; y</code></p>' +
        '<a href="https://example.com/a?b=1&amp;c=2" title="t">link</a>'
    )

    expect(html).toContain('<h1>Заголовок</h1>')
    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('<em>it</em>')
    expect(html).toContain('<code>x &lt; y</code>')
    expect(html).toContain('href="https://example.com/a?b=1&amp;c=2"')
  })

  it('keeps relative shared links', () => {
    const html = sanitizeHtml('<a href="/shared/canvas/abc/note/def">note</a>')

    expect(html).toContain('href="/shared/canvas/abc/note/def"')
  })

  it('removes style attributes', () => {
    const html = sanitizeHtml('<p style="color:red">x</p>')

    expect(html).toBe('<p>x</p>')
  })
})
