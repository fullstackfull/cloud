import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { MoneyText } from '@/components/MoneyText'
import { useControlledGatewayDecision } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import type { PaymentLaunchState } from './usePaymentLaunch'
import { safeLabel } from '@/lib/safeLabel'

/**
 * What the customer is told after they ask to pay.
 *
 * Five states, five sentences, and the two dangerous ones are spelled out:
 *
 *  - a failure names the reason and offers another attempt, because a card
 *    that was declined is a card the customer can replace;
 *  - a payment the provider has not decided asks the customer to wait, and
 *    deliberately offers no retry — a second attempt against money that is
 *    already moving is how somebody gets charged twice.
 *
 * Nothing here says an invoice is paid. That word belongs to the invoice, and
 * the invoice gets it from the provider.
 */
export function PaymentNextAction({ state }: { state: PaymentLaunchState }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const confirm = useControlledGatewayDecision()
  const confirmError = describeError(confirm.error)

  if (state.type === 'idle') return null

  if (state.type === 'redirecting') {
    return <Alert tone="info">{t('payments.redirecting')}</Alert>
  }

  if (state.type === 'client_confirmation') {
    return (
      <div className="flex flex-col gap-3" data-testid="payment-client-confirmation">
        <Alert tone="info">{t('payments.confirmHereBody')}</Alert>

        <p className="text-sm text-[var(--text-secondary)]">
          {t('payments.amountToConfirm')} <MoneyText value={state.payment.amount} />
        </p>

        {confirmError !== null ? (
          <Alert tone="error" requestId={confirmError.requestId}>
            {confirmError.message}
          </Alert>
        ) : null}

        {confirm.isSuccess ? (
          <Alert tone="success">{t('payments.confirmedWithProvider')}</Alert>
        ) : (
          <Button
            loading={confirm.isPending}
            onClick={() => {
              confirm.mutate({
                reference: state.reference,
                decision: 'confirm',
                clientSecret: state.clientSecret,
              })
            }}
          >
            {t('payments.confirmPayment')}
          </Button>
        )}
      </div>
    )
  }

  if (state.type === 'completed') {
    // The provider has the money. The invoice says whether the platform has
    // been told, and this screen does not answer that question for it.
    return <Alert tone="info">{t('payments.takenByProvider')}</Alert>
  }

  if (state.type === 'failed') {
    return (
      <Alert tone="error">
        <span className="flex flex-col gap-1">
          <span>{t('payments.declined')}</span>
          {state.failureCode !== null ? (
            <span className="text-xs">
              {safeLabel('paymentFailure', state.failureCode)}
            </span>
          ) : null}
        </span>
      </Alert>
    )
  }

  /*
   * Pending. No retry button, on purpose.
   */
  return <Alert tone="warning">{t('payments.awaitingProvider')}</Alert>
}
