import { useState } from 'react'
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
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { newIdempotencyKey } from '@/lib/api'
import { useDedicatedPower, useDedicatedReinstall, useDedicatedServers } from '@/lib/queries'
import type { DedicatedServer } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

const POWER_ACTIONS = ['on', 'off', 'cycle'] as const

export function DedicatedPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useDedicatedServers(page)
  const power = useDedicatedPower()
  const reinstall = useDedicatedReinstall()

  /*
   * The whole row rather than an id: the dialogue shows the serial and
   * requires it typed back, and reading it from a list that may have refetched
   * underneath is how somebody types one machine's serial and rebuilds another.
   */
  const [rebuilding, setRebuilding] = useState<DedicatedServer | null>(null)

  /*
   * The machine about to have its power cut at the chassis. A plain
   * confirmation rather than a typed one: the machine survives it and can be
   * powered on again from this page (the graded policy in the audit, BD-6).
   */
  const [forcingOff, setForcingOff] = useState<DedicatedServer | null>(null)

  const displayed = describeError(power.error)
  const reinstallError = describeError(reinstall.error)

  const columns: Array<Column<DedicatedServer>> = [
    {
      key: 'machine',
      header: t('dedicated.machine'),
      ltr: true,
      cell: (server) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">
            {server.manufacturer} {server.model}
          </p>
          <p className="technical text-xs text-[var(--text-muted)]">{server.serial}</p>
        </div>
      ),
    },
    { key: 'status', header: t('dedicated.status'), cell: (server) => <StatusBadge status={server.status} /> },
    {
      key: 'power',
      header: t('dedicated.power'),
      cell: (server) => (
        <div className="flex flex-col items-start gap-1">
          <StatusBadge status={server.power_state} />
          {/*
            * Why the buttons on this row are off, in the customer's words —
            * published by the API from the facts its guard refuses on.
            */}
          {server.actions.blocked_reason === null ? null : (
            <span className="text-xs text-[var(--warning-text)]">
              {t(`dedicated.blocked.${server.actions.blocked_reason}`, { defaultValue: server.actions.blocked_reason })}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'since',
      header: t('dedicated.since'),
      cell: (server) =>
        server.activated_at == null ? '—' : formatDate(server.activated_at, locale),
    },
    {
      key: 'rebuild',
      header: t('dedicated.rebuild'),
      cell: (server) =>
        server.reinstall === null ? (
          <span className="text-xs text-[var(--text-muted)]">—</span>
        ) : (
          <span
            className={
              server.reinstall.needs_attention
                ? 'text-xs text-[var(--danger-text)]'
                : 'text-xs text-[var(--text-muted)]'
            }
          >
            {t(`dedicated.reinstallState.${server.reinstall.state}`, {
              defaultValue: server.reinstall.state,
            })}
          </span>
        ),
    },
    {
      key: 'actions',
      header: '',
      cell: (server) => (
        <div className="flex flex-wrap gap-1">
          {POWER_ACTIONS.map((action) => (
            <Button
              key={action}
              size="sm"
              variant={action === 'off' ? 'danger' : 'ghost'}
              disabled={! server.actions.power}
              loading={
                power.isPending && power.variables.id === server.id && power.variables.action === action
              }
              onClick={() => {
                if (action === 'off') {
                  setForcingOff(server)
                  return
                }

                power.mutate({ id: server.id, action, idempotencyKey: newIdempotencyKey() })
              }}
            >
              {t(`dedicated.actions.${action}`)}
            </Button>
          ))}
          <Button
            size="sm"
            variant="danger"
            // Disabled on the API's own word — not in service, or a job
            // live against the machine — so the screen does not offer a
            // button the endpoint would answer 409 to.
            disabled={! server.actions.reinstall}
            onClick={() => { setRebuilding(server); }}
          >
            {t('dedicated.actions.reinstall')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.dedicated')} description={t('dedicated.subtitle')} />

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
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.dedicated')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(server) => server.id}
              empty={t('dedicated.empty')}
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
        open={forcingOff !== null}
        title={t('dedicated.forceOff.title', { serial: forcingOff?.serial ?? '' })}
        body={<p>{t('dedicated.forceOff.body')}</p>}
        confirmLabel={t('dedicated.forceOff.confirmLabel')}
        loading={power.isPending}
        error={displayed?.message}
        onConfirm={() => {
          if (forcingOff === null) return

          power.mutate(
            { id: forcingOff.id, action: 'off', idempotencyKey: newIdempotencyKey() },
            { onSettled: () => { setForcingOff(null); } },
          )
        }}
        onCancel={() => { setForcingOff(null); }}
      />

      <ConfirmDialog
        open={rebuilding !== null}
        title={t('dedicated.reinstall.title', { serial: rebuilding?.serial ?? '' })}
        body={
          <>
            <p className="font-medium text-[var(--danger-text)]">{t('dedicated.reinstall.warning')}</p>
            <p className="mt-2">{t('dedicated.reinstall.advice')}</p>
          </>
        }
        /*
         * Guarded against the null case explicitly: `rebuilding?.serial` would
         * be undefined with no dialogue open, and an undefined required phrase
         * means "no confirmation needed" — the one default this control must
         * never take.
         */
        requiredPhrase={rebuilding?.serial ?? '\u0000'}
        requiredPhraseLabel={t('dedicated.reinstall.phraseLabel')}
        confirmLabel={t('dedicated.reinstall.confirmLabel')}
        loading={reinstall.isPending}
        error={reinstallError?.message}
        onConfirm={(phrase) => {
          if (rebuilding === null) return

          reinstall.mutate(
            {
              id: rebuilding.id,
              // Sent as typed. The server compares it and decides.
              confirm_serial: phrase,
              idempotencyKey: newIdempotencyKey(),
            },
            { onSuccess: () => { setRebuilding(null); } },
          )
        }}
        onCancel={() => { setRebuilding(null); }}
      />
    </>
  )
}
