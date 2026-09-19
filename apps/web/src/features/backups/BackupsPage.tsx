import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { SelectField } from '@/components/SelectField'
import { useVirtualMachines } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'

import { BackupsForMachine } from './BackupsForMachine'

/**
 * Backups across the account: pick a machine, then its archives.
 *
 * Since Wave 3 this page is a machine picker in front of the section that also
 * lives on the machine's own page. It stays because it is the honest home for
 * "show me every backup I have" and for a customer who thinks in backups
 * rather than in servers — the audit's complaint was that it was the *only*
 * way in, not that it existed.
 *
 * The API nests backups under a machine because a backup is *of* something,
 * and both screens keep that shape rather than presenting one flat list across
 * an account: a flat list is where a customer with two servers restores the
 * wrong one.
 */
export function BackupsPage() {
  const { t } = useTranslation()
  const { data: machines, isPending, error } = useVirtualMachines(1)
  const [selected, setSelected] = useState<string | null>(null)

  const rows = machines?.data ?? []

  useEffect(() => {
    // Select the first machine once, and re-select if the current one
    // disappears — a machine that was terminated while this page was open.
    if (rows.length === 0) return
    if (selected !== null && rows.some((vm) => vm.id === selected)) return

    setSelected(rows[0]?.id ?? null)
  }, [rows, selected])

  const machine: VirtualMachine | undefined = rows.find((vm) => vm.id === selected)

  return (
    <>
      <PageHeader title={t('nav.backups')} description={t('backups.subtitle')} />

      <LoadFailure error={error} />

      {isPending ? (
        <Loading />
      ) : rows.length === 0 ? (
        <EmptyState>
          {t('backups.noMachines')} {t('backups.noMachinesHint')}
        </EmptyState>
      ) : (
        <div className="flex flex-col gap-6">
          <Card>
            <SelectField
              label={t('backups.machine')}
              value={selected ?? ''}
              onChange={(event) => { setSelected(event.target.value); }}
              options={rows.map((vm) => ({ value: vm.id, label: vm.hostname }))}
            />
          </Card>

          {/*
            * Keyed by machine, so switching machines starts the section fresh
            * rather than showing page three of the previous machine's list.
            */}
          {machine === undefined ? null : <BackupsForMachine key={machine.id} vm={machine} />}
        </div>
      )}
    </>
  )
}
