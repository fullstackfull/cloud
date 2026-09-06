import { describe, expect, it } from 'vitest'

import { safeRedirect } from '../safeRedirect'

describe('safeRedirect', () => {
  it('keeps a same-site path', () => {
    expect(safeRedirect('/security')).toBe('/security')
    expect(safeRedirect('/services?page=2')).toBe('/services?page=2')
  })

  it.each([
    ['//evil.example/steal', 'a protocol-relative URL'],
    ['https://evil.example', 'an absolute URL'],
    ['http://evil.example', 'an insecure absolute URL'],
    ['javascript:alert(1)', 'a javascript URL'],
    ['/\\evil.example', 'a backslash after the slash'],
    ['\\\\evil.example', 'a UNC-style path'],
    ['java\tscript:alert(1)', 'a scheme split by a control character'],
    ['/safe\npath', 'an embedded newline'],
    ['services', 'a bare relative path'],
    ['', 'an empty string'],
  ])('refuses %s (%s)', (candidate) => {
    expect(safeRedirect(candidate)).toBe('/')
  })

  it('refuses anything that is not a string', () => {
    expect(safeRedirect(undefined)).toBe('/')
    expect(safeRedirect(null)).toBe('/')
    expect(safeRedirect({ toString: () => '/admin' })).toBe('/')
  })

  it('honours a caller-supplied fallback', () => {
    expect(safeRedirect('https://evil.example', '/sign-in')).toBe('/sign-in')
  })
})
