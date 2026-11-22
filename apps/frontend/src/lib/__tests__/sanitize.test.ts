// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { sanitizeHtml } from '../sanitize'

/**
 * R18: klientskiy sanitayzer dlya budushchego Tiptap (R28) i /shared (R32).
 * Proveryaem, chto opasnyy HTML/URL ne vyzhivayut, a razreshyonnaya razmetka —
 * sokhranyaetsya.
 */
describe('sanitizeHtml', () => {
  it('removes scripts and event handlers', () => {
    const html = sanitizeHtml('<p onclick="alert(1)">ok</p><script>alert(2)</script>')

    expect(html).toContain('<p>ok</p>')
    expect(html).not.toContain('<script')
    expect(html).not.toContain('onclick')
  })

  it('removes dangerous img attributes and unsafe src', () => {
    const html = sanitizeHtml('<img src=x onerror="alert(1)" alt="x">')

    expect(html).not.toContain('onerror')
    expect(html).not.toContain('src="x"')
  })

  it('keeps safe images', () => {
    const html = sanitizeHtml('<img src="https://example.com/a.png" alt="A" loading="lazy">')

    expect(html).toContain('<img')
    expect(html).toContain('src="https://example.com/a.png"')
    expect(html).toContain('alt="A"')
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
      '<h1>Zagolovok</h1><p><strong>bold</strong> <em>it</em> <code>x &lt; y</code></p>' +
        '<a href="https://example.com/a?b=1&amp;c=2" title="t">link</a>'
    )

    expect(html).toContain('<h1>Zagolovok</h1>')
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
