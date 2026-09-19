import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useWatchOperations } from '@/features/operations/watchChannel'
import { RESOURCE_FAMILIES } from '@/features/resources/resourcePaths'
import { newIdempotencyKey } from '@/lib/api'
import { useVpsPower } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * `stop` and `shutdown` are two buttons because they are two things: one pulls
 * the plug, the other asks the guest to close its files first. Collapsing them
 * into one control is how a customer loses a database.
 */
const POWER_ACTIONS = ['start', 'shutdown', 'stop', 'reboot'] as const

export type PowerAction = (typeof POWER_ACTIONS)[number]

/**
 * The four power controls, wherever a machine is shown.
 *
 * One component, used by the list row and by the machine's own page. The audit
 * warned about exactly this: two UI paths to one mutation is two chances to
 * get the idempotency key, the confirmation or the error handling wrong, and
 * Wave 0 had already found that class of bug once. The mutation hook, the
 * fresh key per press, the force-off confirmation and the error live here and
 * nowhere else.
 */
export function VpsPowerActions({
  vm,
  only,
}: {
  vm: VirtualMachine
  /**
   * Which of the four to render.
   *
   * The list row shows one — a reboot, the thing customers do most — and the
   * machine's own page shows all four. It is the same component either way, so
   * the row's reboot and the page's reboot are the same request with the same
   * key and the same error handling.
   */
  only?: readonly PowerAction[]
}) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const power = useVpsPower()
  const { watch } = useWatchOperations()

  /**
   * Pulling the plug is the one power control that costs data: it does not ask
   * the guest to close its files. So it asks the customer instead — a plain
   * confirmation, not a typed one, because the machine survives it and can be
   * started again from the same place (the graded policy, BD-6).
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
            variant={action === 'stop' ? 'danger' : 'ghost'}
            /*
             * Disabled on the API's own word about whether it would accept the
             * request, not on a guess made here from the badges.
             */
            disabled={!vm.actions.power}
            loading={
              power.isPending && power.variables.id === vm.id && power.variables.action === action
            }
            onClick={() => {
              if (action === 'stop') {
                setForcingOff(true)
                return
              }

              power.mutate(
                {
                  id: vm.id,
                  action,
                  // A fresh key per press: two deliberate reboots are two
                  // operations, and only a retry of the same press should
                  // collapse into one.
                  idempotencyKey: newIdempotencyKey(),
                },
                {
                  /*
                   * The 202 is handed straight to the observation layer, which
                   * acknowledges it, follows it, and says how it ended — from
                   * whatever screen the customer is on by then.
                   */
                  onSuccess: (accepted) => {
                    watch(accepted, {
                      actionKey: `operations.actions.${action}`,
                      href: RESOURCE_FAMILIES.vps.detail(vm.id),
                      invalidate: ['vps', 'services', 'activity', 'overview'],
                    })
                  },
                },
              )
            }}
          >
            {t(`vps.actions.${action}`)}
          </Button>
        ))}
      </div>

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}

      {/*
        * Mounted only while it is open. A closed <dialog> stays in the
        * document, and its confirm button — hidden, but still labelled
        * "Force off" — is a button anything counting the controls on this
        * page would find, and one a stale reference could still be clicked
        * through.
        */}
      {forcingOff ? (
      <ConfirmDialog
        open
        title={t('vps.forceOff.title', { hostname: vm.hostname })}
        body={<p>{t('vps.forceOff.body')}</p>}
        confirmLabel={t('vps.forceOff.confirmLabel')}
        loading={power.isPending}
        {...(failure === null ? {} : { error: failure.message })}
        onConfirm={() => {
          power.mutate(
            { id: vm.id, action: 'stop', idempotencyKey: newIdempotencyKey() },
            {
              onSuccess: (accepted) => {
                watch(accepted, {
                  actionKey: 'operations.actions.stop',
                  href: RESOURCE_FAMILIES.vps.detail(vm.id),
                  invalidate: ['vps', 'services', 'activity', 'overview'],
                })
              },
              onSettled: () => { setForcingOff(false); },
            },
          )
        }}
        onCancel={() => { setForcingOff(false); }}
      />
      ) : null}
    </div>
  )
}
