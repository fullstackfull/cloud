import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * §26–§28. One design system, not twenty-three hand-rolled approximations.
 *
 * ## What the sweep found
 *
 * Before Wave 5, customer screens carried **23** form controls written by
 * hand, outside the three field components that existed:
 *
 * | control          | count | where |
 * | ---------------- | ----- | ----- |
 * | `<select>`       | 9     | country/currency, DNS type, CAA tag, zone import mode, support category, support priority, team invite role, WordPress domain source, WordPress push scope |
 * | `<textarea>`     | 5     | zone file paste, nameserver list, support request, support reply, VPS SSH keys |
 * | `type="checkbox"`| 3     | accept terms, restorable backup file, end subscription now |
 * | `type="file"`    | 2     | support attachments, zone file upload |
 * | `type="radio"`   | 2     | account type (individual / organization) |
 * | `type="text"`    | 1     | console input |
 * | **total**        | **22**| |
 *
 * (Plus one more `<select>` in the team members table, which Wave 5 rewired
 * for its own reasons — 23 with it.)
 *
 * Every one of them had its own padding, its own border colour and its own
 * focus ring. Six had a `<span>` where the `<label>` should have been, which
 * is a control a screen reader announces as unlabelled. Four named
 * `--border`/`--surface` directly instead of the field tokens, so they did not
 * change with the rest of the form. None carried `aria-invalid`, and none
 * wired an error to `aria-describedby`.
 *
 * After: **0**, and the components grew a `FileField` and a `RadioGroup` to
 * absorb the last two families.
 *
 * ## Why a test and not a note
 *
 * Because the count went from 23 to 0 once before, in the sense that somebody
 * wrote Field, SelectField and TextareaField and then twenty more controls
 * were written beside them. A note in a report does not survive the next
 * screen; this does.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

/**
 * Customer surfaces. The operator area is out of scope for Wave 5, and
 * sweeping it here would mean a failing test nobody in this wave may fix.
 */
const CUSTOMER_DIRECTORIES = [
  'app',
  'components',
  'features/account',
  'features/activity',
  'features/auth',
  'features/backups',
  'features/billing',
  'features/catalog',
  'features/console',
  'features/dns',
  'features/domains',
  'features/infrastructure',
  'features/notifications',
  'features/operations',
  'features/orders',
  'features/payments',
  'features/resources',
  'features/security',
  'features/services',
  'features/support',
  'features/team',
  'features/tokens',
  'features/wallet',
  'features/wordpress',
]

/**
 * The components allowed to contain a raw control: they are the ones whose
 * whole job is wrapping it. ConfirmDialog is here because the typed-phrase box
 * is part of the confirmation contract rather than a field on a form.
 */
const DESIGN_SYSTEM = [
  'components/Field.tsx',
  'components/FieldShell.tsx',
  'components/SelectField.tsx',
  'components/TextareaField.tsx',
  'components/CheckboxField.tsx',
  'components/FileField.tsx',
  'components/RadioGroup.tsx',
  'components/Switch.tsx',
  'components/ConfirmDialog.tsx',
]

const CONTROLS: Array<[string, RegExp]> = [
  ['<select>', /<select[\s>]/],
  ['<textarea>', /<textarea[\s>]/],
  ['<input>', /<input[\s>]/],
]

describe('every form control on a customer screen', () => {
  it('has files to check', () => {
    expect(customerSources().length).toBeGreaterThan(80)
  })

  it.each(CONTROLS)('is never a hand-rolled %s', (name, pattern) => {
    const offences: string[] = []

    for (const file of customerSources()) {
      const relative = path.relative(SOURCE, file)

      if (DESIGN_SYSTEM.includes(relative)) continue

      const source = withoutComments(readFileSync(file, 'utf8'))

      if (pattern.test(source)) offences.push(relative)
    }

    expect(
      offences,
      `${name} written by hand. Use the design-system field component instead: ` +
        'a control outside it has no label binding, no aria-invalid, no error ' +
        'wiring, and a focus ring that does not match the form around it.',
    ).toEqual([])
  })

  it('never names a raw palette colour', () => {
    /*
     * `text-red-600 dark:text-red-400` was how one field error was coloured.
     * It is the same red in light and a different one in dark, it is not the
     * red the Alert beside it uses, and it does not move when the palette
     * does. Errors come from --danger-text, everywhere.
     */
    const offences: string[] = []

    for (const file of customerSources()) {
      const source = withoutComments(readFileSync(file, 'utf8'))

      for (const match of source.matchAll(
        /\b(?:bg|text|border|ring|outline|from|to|via)-(red|green|amber|yellow|orange|blue|slate|gray|grey|zinc|neutral|stone)-\d{2,3}\b/g,
      )) {
        offences.push(`${path.relative(SOURCE, file)} → ${match[0]}`)
      }
    }

    expect(offences, 'Use a design token, so both themes and every screen move together.').toEqual(
      [],
    )
  })
})

function customerSources(): string[] {
  const files: string[] = []

  for (const directory of CUSTOMER_DIRECTORIES) {
    walk(path.join(SOURCE, directory), files)
  }

  return files.filter((file) => ! file.includes('__tests__'))
}

function walk(directory: string, into: string[]): void {
  for (const entry of readdirSync(directory)) {
    const full = path.join(directory, entry)

    if (statSync(full).isDirectory()) {
      walk(full, into)
    } else if (full.endsWith('.tsx')) {
      into.push(full)
    }
  }
}

/** Source with comments removed, so an explanation cannot trip its own gate. */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}
