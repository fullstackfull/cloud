import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * W5.7. One mutation path per business action.
 *
 * The failure this prevents is a pair of screens that both do the same thing
 * and do it differently: the machines list posting a power action one way and
 * the machine's own page posting it another. They diverge the first time one
 * of them is fixed — a confirmation added here, an idempotency key added
 * there — and the divergence is invisible until a customer reboots from the
 * list and does not get the dialogue they got yesterday.
 *
 * Shared *presentation* is not the requirement and is not asserted: a list row
 * and a resource page can look nothing alike. Shared *mutation truth* is, and
 * it has a mechanical definition — one endpoint is written from one place —
 * which is what this file checks.
 *
 * It is not a style rule. Two screens that already call the same hook satisfy
 * it whatever their markup, and the gate stays silent.
 */

const SOURCE = path.join(import.meta.dirname, '../..')

/**
 * Modules allowed to declare a mutation, and what each one owns.
 *
 * The list is short on purpose. A component that declares its own
 * `useMutation` is a second implementation of something: either the hook
 * already exists in one of these modules, or it belongs in one of them.
 */
const MUTATION_MODULES: Record<string, string> = {
  'lib/queries.ts': 'Every customer business mutation.',
  'lib/adminQueries.ts': 'The operator screens’ mutations.',
  'lib/controlCenterQueries.ts': 'The Control Center’s mutations.',
  'features/account/useProfile.ts': 'The person’s own profile, password and sessions.',
  'features/auth/useAuth.ts': 'Signing in, the two-factor challenge, signing out.',
  'features/security/useTwoFactor.ts': 'Turning two-factor on and off, and its recovery codes.',
  'features/auth/RegisterPage.tsx': 'Registration, which belongs to the one screen that offers it.',
  'features/auth/ForgotPasswordPage.tsx': 'Asking for a reset link.',
  'features/auth/ResetPasswordPage.tsx': 'Setting a password from a reset link.',
}

interface Write {
  method: string
  endpoint: string
  where: string
}

function files(): string[] {
  const found: string[] = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        if (entry === '__tests__') continue

        walk(full)
      } else if (full.endsWith('.ts') || full.endsWith('.tsx')) {
        found.push(full)
      }
    }
  }

  walk(SOURCE)

  return found
}

function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(?<!:)\/\/[^\n]*/g, '')
}

/**
 * Every write the portal makes, with the endpoint normalised.
 *
 * The generics are skipped by balancing `<` and `>` rather than by a regex:
 * `api.post<Envelope<{ customer_id: string }>>('/invitations/…')` has a brace
 * inside its type argument, and a pattern that stopped at the first `>` missed
 * the call entirely — which is how a scan of this kind reports "no
 * duplicates" while looking at two thirds of the code.
 */
function writes(): Write[] {
  const found: Write[] = []

  for (const file of files()) {
    const source = withoutComments(readFileSync(file, 'utf8'))

    for (const match of source.matchAll(/api\.(post|put|patch|delete)/g)) {
      let cursor = match.index + match[0].length

      while (source[cursor] === ' ' || source[cursor] === '\n') cursor += 1

      if (source[cursor] === '<') {
        let depth = 0

        while (cursor < source.length) {
          if (source[cursor] === '<') depth += 1
          if (source[cursor] === '>') {
            depth -= 1

            if (depth === 0) {
              cursor += 1

              break
            }
          }

          cursor += 1
        }
      }

      while (source[cursor] === ' ' || source[cursor] === '\n') cursor += 1

      if (source[cursor] !== '(') continue

      cursor += 1

      while (source[cursor] === ' ' || source[cursor] === '\n') cursor += 1

      const quote = source[cursor]

      if (quote !== "'" && quote !== '"' && quote !== '`') continue

      const end = source.indexOf(quote, cursor + 1)

      if (end === -1) continue

      found.push({
        method: (match[1] ?? '').toUpperCase(),
        // Interpolated identifiers differ per call site and are not part of
        // the endpoint's identity.
        endpoint: source.slice(cursor + 1, end).replace(/\$\{[^}]*\}/g, '{}'),
        where: `${path.relative(SOURCE, file)}:${String(source.slice(0, match.index).split('\n').length)}`,
      })
    }
  }

  return found
}

describe('changing something', () => {
  it('finds every write in the portal, so a broken scan cannot pass', () => {
    // The count is a floor. It was 73 when W5.7 measured it and a parser that
    // matched nothing would otherwise satisfy every assertion below.
    expect(writes().length).toBeGreaterThan(70)
  })

  it('writes each endpoint from exactly one place', () => {
    const byEndpoint = new Map<string, Write[]>()

    for (const write of writes()) {
      const key = `${write.method} ${write.endpoint}`

      byEndpoint.set(key, [...(byEndpoint.get(key) ?? []), write])
    }

    const duplicated = [...byEndpoint.entries()]
      .filter(([, calls]) => calls.length > 1)
      .map(([key, calls]) => `${key}  <- ${calls.map((call) => call.where).join(', ')}`)

    expect(
      duplicated,
      'endpoints written from more than one place. Two screens may look different; they must ' +
        'not each implement the same action.',
    ).toEqual([])
  })

  it('declares mutations only in the modules that own them', () => {
    const strays: string[] = []

    for (const file of files()) {
      const relative = path.relative(SOURCE, file)

      if (relative in MUTATION_MODULES) continue

      if (/\buseMutation\(/.test(withoutComments(readFileSync(file, 'utf8')))) strays.push(relative)
    }

    expect(
      strays,
      'files declaring a mutation outside the modules that own one. Move it to the module for ' +
        'its area, or add the file here with what it owns.',
    ).toEqual([])
  })

  it('gives every mutation module a stated scope, and keeps the list current', () => {
    for (const [module, scope] of Object.entries(MUTATION_MODULES)) {
      expect(scope.length, `${module} has no scope written`).toBeGreaterThan(15)

      const source = withoutComments(
        readFileSync(path.join(SOURCE, module), 'utf8'),
      )

      expect(/\buseMutation\(/.test(source), `${module} no longer declares a mutation`).toBe(true)
    }
  })
})
