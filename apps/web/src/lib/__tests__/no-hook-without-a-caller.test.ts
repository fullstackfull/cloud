import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * Every hook the data layer publishes is called by something.
 *
 * The gate that would have caught what Wave 3 spent most of its time on: the
 * platform could list a machine's installable images, read a hosting
 * account's real usage, renew a domain, set its auto-renew, read and correct
 * its registrant, and edit a DNS record — and not one of those endpoints had
 * a caller anywhere in the portal. A capability with no caller is a promise
 * in the API description and nothing on a screen.
 *
 * W5.7 widened it. It read `lib/queries.ts` only, which is the customer data
 * layer; the operator modules were outside it, and the audit found three
 * hooks there with no caller — one per shipped endpoint no screen offers yet.
 * They are listed below rather than deleted, because deleting them would
 * delete the client half of a working API, and each entry says which screen
 * would use it.
 *
 * A test is not a caller: a capability proven only by its own test is still a
 * capability no customer or operator can reach.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

/** The modules that publish hooks, and the floor each must keep publishing. */
const DATA_LAYER: Record<string, number> = {
  'lib/queries.ts': 80,
  'lib/adminQueries.ts': 20,
  'lib/controlCenterQueries.ts': 20,
}

/**
 * Hooks with no caller, each with the reason it is kept.
 *
 * FUTURE_PREPARED, not dead: the endpoint behind each is shipped and audited,
 * and the screen that would use it is named. An entry here is a decision
 * somebody made, and the staleness test below deletes it the moment a screen
 * starts calling the hook.
 */
const PREPARED_WITHOUT_A_SCREEN: Record<string, string> = {
  useAssessProvider:
    'POST /providers/{id}/assess. The Providers screen tests a credential but does not yet re-derive what the account can do.',
  useServer:
    'GET /infrastructure/servers/{id}. The machines screen renders the selected row from the page it already has; a machine of its own is the next screen.',
  useAssignTicket:
    'PUT /support/tickets/{id}/assignee. Queue management the operator support screen has no surface for yet.',
}

function sourceFiles(directory: string, prefix = ''): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const relative = prefix === '' ? entry.name : `${prefix}/${entry.name}`

    if (entry.isDirectory()) {
      return entry.name === '__tests__' ? [] : sourceFiles(path.join(directory, entry.name), relative)
    }

    return /\.tsx?$/.test(entry.name) ? [relative] : []
  })
}

function publishedHooks(): Array<{ name: string; module: string }> {
  const hooks: Array<{ name: string; module: string }> = []

  for (const [module, floor] of Object.entries(DATA_LAYER)) {
    const source = readFileSync(path.join(SOURCE, module), 'utf8')
    const found = [...source.matchAll(/^export function (use\w+)/gm)].map((match) => match[1] ?? '')

    // A floor per module, so a regex that stops matching fails the gate
    // rather than passing on an empty list.
    expect(found.length, `${module} publishes fewer hooks than it did`).toBeGreaterThan(floor)

    for (const name of found) hooks.push({ name, module })
  }

  return hooks
}

function callersOutsideTheDataLayer(): string {
  return sourceFiles(SOURCE)
    .filter((file) => ! (file in DATA_LAYER))
    .map((file) => readFileSync(path.join(SOURCE, file), 'utf8'))
    .join('\n')
}

describe('no dead capability', () => {
  it('has a caller for every hook the data layer publishes', () => {
    const callers = callersOutsideTheDataLayer()

    const uncalled = publishedHooks()
      .filter(({ name }) => ! new RegExp(`\\b${name}\\b`).test(callers))
      .filter(({ name }) => ! (name in PREPARED_WITHOUT_A_SCREEN))
      .map(({ name, module }) => `${name}  (${module})`)

    expect(
      uncalled,
      'hooks nothing calls. Either give them a screen, or add them to ' +
        'PREPARED_WITHOUT_A_SCREEN with the endpoint and the screen that would use it.',
    ).toEqual([])
  })

  it('names the endpoint and the screen for every prepared hook', () => {
    for (const [hook, reason] of Object.entries(PREPARED_WITHOUT_A_SCREEN)) {
      expect(reason.length, `${hook} has no reason`).toBeGreaterThan(40)
      expect(/GET|POST|PUT|PATCH|DELETE/.test(reason), `${hook}'s reason names no endpoint`).toBe(true)
    }
  })

  it('has no stale entry: a prepared hook that something now calls', () => {
    const callers = callersOutsideTheDataLayer()

    expect(
      Object.keys(PREPARED_WITHOUT_A_SCREEN).filter((hook) =>
        new RegExp(`\\b${hook}\\b`).test(callers),
      ),
      'hooks listed as having no screen that a screen now calls',
    ).toEqual([])
  })
})
