import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * A gate, not a unit test: one act, one place that performs it.
 *
 * Wave 3 gave every resource a page of its own, which meant every action a
 * list row offered was suddenly offered from two screens. The audit warned
 * about exactly this: two UI paths to one mutation is two chances to get the
 * idempotency key, the confirmation phrase or the error handling wrong, and
 * Wave 0 had already found that class of bug once — a force-off that fired on
 * a single click.
 *
 * So each mutation hook below has one owner, and this test walks the source to
 * prove it. A new screen that wants to reboot a machine imports
 * VpsPowerActions; if it imports the hook instead, this fails here rather than
 * in a customer's support ticket.
 *
 * What this is not: a rule that mutations may not be shared. It is a rule that
 * the *user interface* for a mutation — its confirmation, its key, its
 * disabled state and its refusals — lives in one component. Reads are not
 * listed at all: two screens fetching the same machine is a cache question,
 * not a correctness one.
 */

const SOURCE_ROOT = path.resolve(import.meta.dirname, '../..')

/**
 * Hook → the file that may call it, relative to src/.
 *
 * `lib/queries.ts` is where they are declared and is always allowed. Test
 * files are excluded from the walk: a test may reach for whatever it needs to
 * prove something.
 */
const OWNERS: Record<string, string> = {
  // Power and rebuild, for both machine families. The list row and the
  // machine's page render the same component, with `only` deciding which
  // buttons appear.
  useVpsPower: 'features/infrastructure/vps/VpsPowerActions.tsx',
  useVpsReinstall: 'features/infrastructure/vps/VpsReinstallAction.tsx',
  useDedicatedPower: 'features/infrastructure/dedicated/DedicatedPowerActions.tsx',
  useDedicatedReinstall: 'features/infrastructure/dedicated/DedicatedReinstallAction.tsx',

  // Backups: the machine's own section and the cross-machine screen are the
  // same component, so the typed-hostname confirmation cannot exist in one
  // and be forgotten in the other.
  useCreateBackup: 'features/backups/BackupsForMachine.tsx',
  useRestoreBackup: 'features/backups/BackupsForMachine.tsx',
  useDeleteBackup: 'features/backups/BackupsForMachine.tsx',
  useKeepBackup: 'features/backups/BackupsForMachine.tsx',

  // Ending a subscription decides when somebody's data is destroyed. It is
  // offered from the subscriptions list and from every resource's billing
  // section, and there is one dialogue.
  useCancelSubscription: 'features/billing/CancelSubscriptionDialog.tsx',

  // The panel session is a one-time credential: one component opens it and
  // never renders it.
  useHostingSso: 'features/infrastructure/hosting/HostingPanelButton.tsx',

  // WordPress copies, including the push that overwrites a live site.
  useCreateWordPressStaging: 'features/wordpress/SiteCopies.tsx',
  useCloneWordPressSite: 'features/wordpress/SiteCopies.tsx',
  usePushWordPressToProduction: 'features/wordpress/SiteCopies.tsx',
  useWordPressPushImpact: 'features/wordpress/SiteCopies.tsx',

  // Domains: each act on a name has one door.
  useSetDomainAutoRenew: 'features/domains/DomainAutoRenewToggle.tsx',
  useRenewDomain: 'features/domains/DomainRenewAction.tsx',
  useTransferDomainIn: 'features/domains/DomainTransferInForm.tsx',
  useUpdateDomainContacts: 'features/domains/DomainContactsForm.tsx',
  useSetDomainNameservers: 'features/domains/DomainNameserversForm.tsx',
  useSetDomainTransferLock: 'features/domains/DomainLeavingPanel.tsx',
  useDomainAuthorisationCode: 'features/domains/DomainLeavingPanel.tsx',

  // DNS records, including the edit that must not become a delete and an add.
  useAddDnsRecord: 'features/dns/ZoneRecords.tsx',
  useUpdateDnsRecord: 'features/dns/ZoneRecords.tsx',
  useRemoveDnsRecord: 'features/dns/ZoneRecords.tsx',
  useReleaseDnsZone: 'features/dns/DnsZoneDetailPage.tsx',
}

/** Where the hooks are declared, and so where their names legitimately appear. */
const DECLARATION = 'lib/queries.ts'

function sourceFiles(directory: string, prefix = ''): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const relative = prefix === '' ? entry.name : `${prefix}/${entry.name}`

    if (entry.isDirectory()) {
      // A test may import whatever it needs in order to prove something.
      return entry.name === '__tests__' ? [] : sourceFiles(path.join(directory, entry.name), relative)
    }

    return /\.tsx?$/.test(entry.name) ? [relative] : []
  })
}

describe('one mutation path per act', () => {
  const files = sourceFiles(SOURCE_ROOT)

  it('has every destructive or stateful mutation hook called from exactly one component', () => {
    const trespassers: string[] = []

    for (const file of files) {
      if (file === DECLARATION) continue

      const source = readFileSync(path.join(SOURCE_ROOT, file), 'utf8')

      for (const [hook, owner] of Object.entries(OWNERS)) {
        // Word-bounded, so useVpsPower does not match a longer name.
        if (!new RegExp(`\\b${hook}\\b`).test(source)) continue
        if (file === owner) continue

        trespassers.push(`${file} calls ${hook}, which belongs to ${owner}`)
      }
    }

    expect(trespassers).toEqual([])
  })

  it('has an owner for each hook that actually calls it', () => {
    // The other direction: an owner that stopped using its hook means the
    // mapping above has gone stale and is no longer proving anything.
    const idle = Object.entries(OWNERS).filter(([hook, owner]) => {
      const source = readFileSync(path.join(SOURCE_ROOT, owner), 'utf8')

      return !new RegExp(`\\b${hook}\\b`).test(source)
    })

    expect(idle).toEqual([])
  })
})
