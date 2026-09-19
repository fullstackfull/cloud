import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useWatchOperations } from '@/features/operations/watchChannel'
import { RESOURCE_FAMILIES } from '@/features/resources/resourcePaths'
import { newIdempotencyKey } from '@/lib/api'
import { useDedicatedReinstall } from '@/lib/queries'
import type { DedicatedServer } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Rebuilding a dedicated machine: the warning, and the serial typed back.
 *
 * There is no image chooser here, unlike the VPS. A real rebuild of physical
 * hardware needs an out-of-band installer against a real chassis, which this
 * platform does not have and Wave 3 does not pretend to: the lifecycle is
 * recorded and the hardware side stays unproven. The dialogue therefore
 * promises a request, not an installed operating system, and `data_destroyed`
 * on the resource is what the machine's page reads before saying anything
 * reassuring about a rebuild that failed.
 */
export function DedicatedReinstallAction({ server }: { server: DedicatedServer }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const reinstall = useDedicatedReinstall()
  const { watch } = useWatchOperations()

  const [open, setOpen] = useState(false)

  const failure = describeError(reinstall.error)

  function close() {
    setOpen(false)
    reinstall.reset()
  }

  return (
    <>
      <Button
        size="sm"
        variant="danger"
        // Disabled on the API's own word — not in service, or a job live
        // against the machine — so the screen does not offer a button the
        // endpoint would answer 409 to.
        disabled={!server.actions.reinstall}
        onClick={() => { setOpen(true); }}
      >
        {t('dedicated.actions.reinstall')}
      </Button>

      <ConfirmDialog
        open={open}
        title={t('dedicated.reinstall.title', { serial: server.serial })}
        body={
          <>
            <p className="font-medium text-[var(--danger-text)]">
              {t('dedicated.reinstall.warning')}
            </p>
            <p className="mt-2">{t('dedicated.reinstall.advice')}</p>
          </>
        }
        requiredPhrase={server.serial}
        requiredPhraseLabel={t('dedicated.reinstall.phraseLabel')}
        confirmLabel={t('dedicated.reinstall.confirmLabel')}
        loading={reinstall.isPending}
        {...(failure === null ? {} : { error: failure.message })}
        onConfirm={(phrase) => {
          reinstall.mutate(
            {
              id: server.id,
              // Sent as typed. The server compares it and decides.
              confirm_serial: phrase,
              idempotencyKey: newIdempotencyKey(),
            },
            {
              onSuccess: (accepted) => {
                watch(accepted, {
                  actionKey: 'operations.actions.rebuild',
                  href: RESOURCE_FAMILIES.dedicated.detail(server.id),
                  invalidate: ['dedicated', 'services', 'activity', 'overview'],
                })

                close()
              },
            },
          )
        }}
        onCancel={close}
      />

      {failure === null || open ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}
    </>
  )
}
