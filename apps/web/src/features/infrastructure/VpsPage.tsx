import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useVirtualMachines, useVpsPower, useVpsReinstall } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * `stop` and `shutdown` are shown as two different buttons because they are two
 * different things: one pulls the plug, the other asks the guest to close its
 * files first. Collapsing them into one control is how a customer loses a
 * database.
 */
const POWER_ACTIONS = ['start', 'shutdown', 'stop', 'reboot'] as const

export function VpsPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useVirtualMachines(page)
  const power = useVpsPower()
  const reinstall = useVpsReinstall()

  /**
   * The machine whose rebuild is being confirmed.
   *
   * Held as the whole row rather than an id, because the dialogue has to show
   * the hostname and require it typed back — and reading it from a list that
   * may have refetched underneath is how a customer types one machine's name
   * and rebuilds another.
   */
  const [rebuilding, setRebuilding] = useState<VirtualMachine | null>(null)

  const displayed = describeError(power.error)
  const reinstallError = describeError(reinstall.error)

  const columns: Array<Column<VirtualMachine>> = [
    {
      key: 'hostname',
      header: t('vps.hostname'),
      ltr: true,
      cell: (vm) => <span className="technical font-medium">{vm.hostname}</span>,
    },
    {
      key: 'spec',
      header: t('vps.spec'),
      ltr: true,
      cell: (vm) => (
        <span className="technical text-xs">
          {vm.vcpu} vCPU · {Math.round(vm.memory_mib / 1024)} GiB · {vm.disk_gib} GiB
        </span>
      ),
    },
    {
      key: 'address',
      header: t('vps.address'),
      ltr: true,
      cell: (vm) => (
        <span className="technical">
          {vm.addresses.find((address) => address.is_primary)?.address ?? '—'}
        </span>
      ),
    },
    { key: 'power', header: t('vps.power'), cell: (vm) => <StatusBadge status={vm.power_state} /> },
    {
      key: 'rebuild',
      header: t('vps.rebuild'),
      cell: (vm) =>
        vm.reinstall === null ? (
          <span className="text-xs text-[var(--text-muted)]">—</span>
        ) : (
          <span
            className={
              vm.reinstall.needs_attention
                ? 'text-xs text-[var(--danger-text)]'
                : 'text-xs text-[var(--text-muted)]'
            }
          >
            {t(`vps.reinstallState.${vm.reinstall.state}`, { defaultValue: vm.reinstall.state })}
          </span>
        ),
    },
    {
      key: 'actions',
      header: '',
      cell: (vm) => (
        <div className="flex flex-wrap gap-1">
          {POWER_ACTIONS.map((action) => (
            <Button
              key={action}
              size="sm"
              variant={action === 'stop' ? 'danger' : 'ghost'}
              disabled={! vm.is_operable}
              loading={power.isPending && power.variables.id === vm.id && power.variables.action === action}
              onClick={() => { power.mutate({
                  id: vm.id,
                  action,
                  // A fresh key per press: two deliberate reboots are two
                  // operations, and only a retry of the same press should
                  // collapse into one.
                  idempotency_key: crypto.randomUUID(),
                }); }
              }
            >
              {t(`vps.actions.${action}`)}
            </Button>
          ))}
          {/*
            * A link rather than a button: it navigates, and a customer should
            * be able to open a console in a new tab the way they would any
            * other page. Not hidden on a stopped machine either — a console is
            * exactly what somebody needs when the guest will not boot.
            */}
          <Link
            to={`/vps/${vm.id}/console`}
            className="inline-flex items-center rounded border border-[var(--border)] px-2 py-1 text-xs text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]"
          >
            {t('vps.actions.console')}
          </Link>
          <Button
            size="sm"
            variant="danger"
            /*
             * Disabled on exactly the same fact the API refuses on, and also
             * while a rebuild is already running: offering a button that
             * answers 409 is a door with a sign rather than a closed door.
             */
            disabled={! vm.is_operable || vm.reinstall?.in_flight === true}
            onClick={() => { setRebuilding(vm); }}
          >
            {t('vps.actions.reinstall')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.vps')} description={t('vps.subtitle')} />

      <LoadFailure error={readError} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : (
          <>
            <DataTable
              caption={t('nav.vps')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(vm) => vm.id}
              empty={t('vps.empty')}
            />
            <Paginator
              page={data?.meta.page ?? 1}
              lastPage={data?.meta.last_page ?? 1}
              total={data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>

      <ConfirmDialog
        open={rebuilding !== null}
        title={t('vps.reinstall.title', { hostname: rebuilding?.hostname ?? '' })}
        body={
          <>
            <p className="font-medium text-[var(--danger-text)]">{t('vps.reinstall.warning')}</p>
            <p className="mt-2">{t('vps.reinstall.advice')}</p>
          </>
        }
        /*
         * The machine's own hostname, typed back. Guarded against the null
         * case explicitly: `rebuilding?.hostname` would be undefined with no
         * dialogue open, and an undefined required phrase means "no
         * confirmation needed" — which is the one default this control must
         * never take.
         */
        requiredPhrase={rebuilding?.hostname ?? '\u0000'}
        requiredPhraseLabel={t('vps.reinstall.phraseLabel')}
        confirmLabel={t('vps.reinstall.confirmLabel')}
        loading={reinstall.isPending}
        error={reinstallError?.message}
        onConfirm={(phrase) => {
          if (rebuilding === null) return

          reinstall.mutate(
            {
              id: rebuilding.id,
              // Sent as typed. The server compares it against the machine's
              // hostname and is the one that decides.
              confirm_hostname: phrase,
              idempotency_key: crypto.randomUUID(),
            },
            { onSuccess: () => { setRebuilding(null); } },
          )
        }}
        onCancel={() => { setRebuilding(null); }}
      />
    </>
  )
}
