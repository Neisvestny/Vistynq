/* eslint-disable @typescript-eslint/no-explicit-any -- TODO(R33): Tiptap extension config internals are untyped in tests */
import { describe, expect, it } from 'vitest'
import { setI18nLocale } from '@/i18n'
import { WikiLink, parseWikiLinkText } from '../WikiLink'

describe('parseWikiLinkText', () => {
  it.each([
    ['[[note-1|Zagolovok]]', { noteId: 'note-1', label: 'Zagolovok' }],
    ['[[note-1]]', { noteId: 'note-1', label: 'note-1' }],
    ['  [[ note-1 | Zagolovok ]]  ', { noteId: 'note-1', label: 'Zagolovok' }],
    ['[[id|   ]]', { noteId: 'id', label: 'id' }],
    [
      '[[123e4567-e89b-12d3-a456-426614174000|Note]]',
      { noteId: '123e4567-e89b-12d3-a456-426614174000', label: 'Note' },
    ],
  ])('parses %j', (input, expected) => {
    expect(parseWikiLinkText(input)).toEqual(expected)
  })

  it('parses label containing pipe as part of label (current regex behavior)', () => {
    expect(parseWikiLinkText('[[id|Label|Extra]]')).toEqual({ noteId: 'id', label: 'Label|Extra' })
  })

  it.each([
    [''],
    ['not a link'],
    ['[[|Label]]'],
    ['[[   |Label]]'],
    ['[[]]'],
    ['[[   ]]'],
    ['[[id|]]'],
    ['text [[id]]'],
    ['[[id]] text'],
    ['[[id|Label]'],
    ['[id|Label]]'],
  ])('returns null for %j', (input) => {
    expect(parseWikiLinkText(input)).toBeNull()
  })
})

describe('WikiLink extension config', () => {
  it('has expected name/group/inline/atom/selectable flags', () => {
    expect(WikiLink.config.name).toBe('wikiLink')
    expect(WikiLink.config.group).toBe('inline')
    expect(WikiLink.config.inline).toBe(true)
    expect(WikiLink.config.atom).toBe(true)
    expect(WikiLink.config.selectable).toBe(true)
  })

  it('exposes parseHTML rule for data-wikilink spans', () => {
    const rules = WikiLink.config.parseHTML?.call({} as never)
    expect(rules).toEqual([{ tag: 'span[data-wikilink]' }])
  })
})

describe('WikiLink markdownTokenizer', () => {
  const tokenizer = () => WikiLink.config.markdownTokenizer as any

  it('has inline wikiLink tokenizer config', () => {
    const t = tokenizer()
    expect(t.name).toBe('wikiLink')
    expect(t.level).toBe('inline')
  })

  it('start finds [[ and returns -1 when absent', () => {
    const t = tokenizer()
    expect(t.start('hello [[id]]')).toBe(6)
    expect(t.start('no link here')).toBe(-1)
  })

  it('tokenizes [[id|Label]]', () => {
    const t = tokenizer()
    expect(t.tokenize('[[note-1|Zagolovok]] rest')).toEqual({
      type: 'wikiLink',
      raw: '[[note-1|Zagolovok]]',
      noteId: 'note-1',
      label: 'Zagolovok',
    })
  })

  it('tokenizes [[id]] with label fallback', () => {
    const t = tokenizer()
    expect(t.tokenize('[[note-1]]')).toEqual({
      type: 'wikiLink',
      raw: '[[note-1]]',
      noteId: 'note-1',
      label: 'note-1',
    })
  })

  it('trims target and label', () => {
    const t = tokenizer()
    expect(t.tokenize('[[ note-1 | Label ]]')).toMatchObject({
      noteId: 'note-1',
      label: 'Label',
      raw: '[[ note-1 | Label ]]',
    })
  })

  it('returns undefined for invalid/empty targets', () => {
    const t = tokenizer()
    expect(t.tokenize('[[]]')).toBeUndefined()
    expect(t.tokenize('[[ |Label]]')).toBeUndefined()
    expect(t.tokenize('plain text')).toBeUndefined()
    expect(t.tokenize('[id]')).toBeUndefined()
  })
})

describe('WikiLink parseMarkdown', () => {
  const parseMarkdown = () => WikiLink.config.parseMarkdown as any

  it('parses valid token', () => {
    const result = parseMarkdown()({ noteId: 'n1', label: 'Label' })
    expect(result).toEqual({ type: 'wikiLink', attrs: { noteId: 'n1', label: 'Label' } })
  })

  it('falls back label to noteId', () => {
    expect(parseMarkdown()({ noteId: 'n1' })).toEqual({
      type: 'wikiLink',
      attrs: { noteId: 'n1', label: 'n1' },
    })
  })

  it('supports rawTarget fallback field', () => {
    expect(parseMarkdown()({ rawTarget: 'n1', label: 'L' })).toEqual({
      type: 'wikiLink',
      attrs: { noteId: 'n1', label: 'L' },
    })
  })

  it('returns empty array for empty noteId', () => {
    expect(parseMarkdown()({ noteId: '' })).toEqual([])
    expect(parseMarkdown()({ noteId: '   ' })).toEqual([])
    expect(parseMarkdown()({})).toEqual([])
  })
})

describe('WikiLink renderMarkdown', () => {
  const renderMarkdown = () => WikiLink.config.renderMarkdown as any

  it('renders noteId and label', () => {
    expect(renderMarkdown()({ attrs: { noteId: 'n1', label: 'Label' } })).toBe('[[n1|Label]]')
  })

  it('renders short form when label equals noteId or is empty', () => {
    expect(renderMarkdown()({ attrs: { noteId: 'n1', label: 'n1' } })).toBe('[[n1]]')
    expect(renderMarkdown()({ attrs: { noteId: 'n1', label: '' } })).toBe('[[n1]]')
    expect(renderMarkdown()({ attrs: { noteId: 'n1' } })).toBe('[[n1]]')
  })

  it('trims values and returns empty string for empty noteId', () => {
    expect(renderMarkdown()({ attrs: { noteId: ' n1 ', label: ' Label ' } })).toBe('[[n1|Label]]')
    expect(renderMarkdown()({ attrs: { noteId: '', label: 'Label' } })).toBe('')
    expect(renderMarkdown()({ attrs: { noteId: '   ' } })).toBe('')
    expect(renderMarkdown()({})).toBe('')
  })
})

describe('WikiLink renderHTML/addAttributes/addCommands config', () => {
  it('renderHTML returns wikilink span with attrs and label', () => {
    const renderHTML = WikiLink.config.renderHTML as any
    const result = renderHTML({
      HTMLAttributes: { 'data-note-id': 'n1', 'data-label': 'Label' },
      node: { attrs: { noteId: 'n1', label: 'Label' } },
    })
    expect(result[0]).toBe('span')
    expect(result[1]).toMatchObject({
      'data-wikilink': '',
      class: 'wiki-link-chip',
      'data-note-id': 'n1',
      'data-label': 'Label',
    })
    expect(result[2]).toBe('[[Label]]')
  })

  it('renderHTML falls back to noteId/default label', () => {
    setI18nLocale('en')
    const renderHTML = WikiLink.config.renderHTML as any
    expect(
      renderHTML({ HTMLAttributes: {}, node: { attrs: { noteId: 'n1', label: '' } } })[2]
    ).toBe('[[n1]]')
    expect(renderHTML({ HTMLAttributes: {}, node: { attrs: { noteId: '', label: '' } } })[2]).toBe(
      '[[link]]'
    )
  })

  it('addAttributes defines noteId and label', () => {
    const attrs = (WikiLink.config.addAttributes as any).call({})
    expect(attrs).toHaveProperty('noteId')
    expect(attrs).toHaveProperty('label')
    expect(attrs.noteId.default).toBe('')
    expect(attrs.label.default).toBe('')
  })

  it('addCommands exposes setWikiLink', () => {
    const commands = (WikiLink.config.addCommands as any).call({ name: 'wikiLink' })
    expect(typeof commands.setWikiLink).toBe('function')
  })
})

describe('WikiLink attribute parse/render', () => {
  const attrs = () => (WikiLink.config.addAttributes as any).call({})

  it('noteId parseHTML/renderHTML', () => {
    const a = attrs().noteId
    const el = { getAttribute: (k: string) => (k === 'data-note-id' ? 'n1' : null) }
    expect(a.parseHTML(el)).toBe('n1')
    expect(a.renderHTML({ noteId: 'n1' })).toEqual({ 'data-note-id': 'n1' })
  })

  it('label parseHTML falls back to textContent', () => {
    const a = attrs().label
    expect(a.parseHTML({ getAttribute: () => null, textContent: 'Text' })).toBe('Text')
    expect(a.parseHTML({ getAttribute: () => 'L', textContent: 'Text' })).toBe('L')
    expect(a.renderHTML({ label: 'L' })).toEqual({ 'data-label': 'L' })
  })
})
