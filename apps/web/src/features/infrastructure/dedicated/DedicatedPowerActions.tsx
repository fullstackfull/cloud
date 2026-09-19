import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useWatchOperations } from '@/features/operations/watchChannel'
import { RESOURCE_FAMILIES } from '@/features/resources/resourcePaths'
import { newIdempotencyKey } from '@/lib/api'
import { useDedicatedPower } from '@/lib/queries'
import type { DedicatedServer } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { safeLabel } from '@/lib/safeLabel'

const POWER_ACTIONS = ['on', 'off', 'cycle'] as const

export type DedicatedPowerAction = (typeof POWER_ACTIONS)[number]

/**
 * The chassis power controls, wherever a dedicated machine is shown.
 *
 * One component for the list row and for the machine's own page, so both send
 * the same request with the same fresh key and show the same refusal. Nothing
 * here is a promise about the hardware: the endpoint's idempotency is
 * per-request and not durable across a restart of the platform, so this screen
 * does not claim a repeated press is safe to repeat — Wave 3 leaves that
 * gap open rather than papering over it with a key that looks durable.
 *
 * The management address, the BMC and the rack are not on this page or in this
 * component. A customer never needs them and the platform never publishes
 * them.
 */
export function DedicatedPowerActions({
  server,
  only,
}: {
  server: DedicatedServer
  only?: readonly DedicatedPowerAction[]
}) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const power = useDedicatedPower()
  const { acknowledge } = useWatchOperations()

  /*
   * Cutting power at the chassis does not ask the operating system to close
   * its files. A plain confirmation rather than a typed one: the machine
   * survives it and can be powered on again from the same place (BD-6).
   */
  const [forcingOff, setForcingOff] = useState(false)

  const failure = describeError(power.error)

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {(only ?? POWER_ACTIONS).map((action) => (
          <Button
            key={action}
            size="sm"
            variant={action === 'off' ? 'danger' : 'ghost'}
            // On the API's own word about whether it would accept the request.
            disabled={!server.actions.power}
            loading={
              power.isPending &&
              power.variables.id === server.id &&
              power.variables.action === action
            }
            onClick={() => {
              if (action === 'off') {
                setForcingOff(true)
                return
              }

              power.mutate(
                { id: server.id, action, idempotencyKey: newIdempotencyKey() },
                {
                  /*
                   * Acknowledged, not watched. A chassis's power state is
                   * reported by its controller and shown on the machine's own
                   * page, and this endpoint returns the machine rather than an
                   * operation — so there is nothing to poll and nothing to
                   * claim. What the customer gets is "Stop requested", which
                   * is exactly what happened.
                   */
                  onSuccess: () => {
                    acknowledge(`dedicated-power:${server.id}`, {
                      actionKey: `operations.actions.${action}`,
                      href: RESOURCE_FAMILIES.dedicated.detail(server.id),
                    })
                  },
                },
              )
            }}
          >
            {t(`dedicated.actions.${action}`)}
          </Button>
        ))}
      </div>

      {/*
        * Why the buttons are off, in the customer's words — published by the
        * API from the facts its guard refuses on, never guessed here.
        */}
      {server.actions.blocked_reason === null ? null : (
        <span className="text-xs text-[var(--warning-text)]">
          {safeLabel('dedicated.blocked', server.actions.blocked_reason)}
        </span>
      )}

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}

      <ConfirmDialog
        open={forcingOff}
        title={t('dedicated.forceOff.title', { serial: server.serial })}
        body={<p>{t('dedicated.forceOff.body')}</p>}
        confirmLabel={t('dedicated.forceOff.confirmLabel')}
        loading={power.isPending}
        {...(failure === null ? {} : { error: failure.message })}
        onConfirm={() => {
          power.mutate(
            { id: server.id, action: 'off', idempotencyKey: newIdempotencyKey() },
            {
              onSuccess: () => {
                acknowledge(`dedicated-power:${server.id}`, {
                  actionKey: 'operations.actions.off',
                  href: RESOURCE_FAMILIES.dedicated.detail(server.id),
                })
              },
              onSettled: () => { setForcingOff(false); },
            },
          )
        }}
        onCancel={() => { setForcingOff(false); }}
      />
    </div>
  )
}
