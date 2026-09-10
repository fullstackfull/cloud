import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { useSetDomainAutoRenew } from '@/lib/queries'
import type { Domain } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Whether the platform raises the next invoice before this name lapses.
 *
 * A setting, and treated like one: one press, reversible with the next, no
 * typed confirmation and no idempotency key. BD-6's graded policy puts typed
 * identity behind irreversible acts, and a switch that can be switched back is
 * not one — asking somebody to type their own domain name to change a
 * preference teaches them to type domain names into dialogues.
 *
 * What it is not is a cancellation. Turning it off does not end anything, does
 * not release the name, and does not refund the term already paid for: it
 * means the platform will stop invoicing for the next one, and the sentence
 * beside the switch says exactly that. The audit found customers reading
 * "auto-renew off" as "cancelled".
 */
export function DomainAutoRenewToggle({ domain }: { domain: Domain }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const setAutoRenew = useSetDomainAutoRenew(domain.name)

  const failure = describeError(setAutoRenew.error)

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-sm text-[var(--text-primary)]">
          {domain.auto_renew ? t('domains.autoRenewOn') : t('domains.autoRenewOff')}
        </p>

        <Button
          size="sm"
          variant="secondary"
          disabled={!domain.is_manageable}
          loading={setAutoRenew.isPending}
          onClick={() => { setAutoRenew.mutate(!domain.auto_renew); }}
        >
          {domain.auto_renew ? t('domains.autoRenewTurnOff') : t('domains.autoRenewTurnOn')}
        </Button>
      </div>

      <p className="text-xs text-[var(--text-muted)]">
        {domain.auto_renew ? t('domains.autoRenewOnHint') : t('domains.autoRenewOffHint')}
      </p>

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}
    </div>
  )
}
