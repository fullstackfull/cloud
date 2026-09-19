import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { useControlledGatewayDecision, useControlledGatewayPayment } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The fake provider's payment page, in the portal.
 *
 * It exists so the redirect flow can be walked by a person and by the browser
 * suite: a real gateway hosts a page, the customer authorises or abandons the
 * payment there, and the gateway then tells the platform what happened over a
 * signed callback. Every one of those steps is real here except the money.
 *
 * The endpoints behind the two buttons are refused in production and refused
 * whenever a real provider is configured, and neither of them marks anything
 * paid: approving makes the provider send the platform a signed webhook, and
 * the invoice is settled by that webhook. When the decision is taken the
 * browser is sent back to the invoice, which shows what the platform now
 * records — never what this page claims.
 */
export function ControlledGatewayPage() {
  const { t } = useTranslation()
  const { reference = '' } = useParams()
  const navigate = useNavigate()
  const describeError = useApiErrorMessage()

  const { data, isPending, error } = useControlledGatewayPayment(reference)
  const decide = useControlledGatewayDecision()
  const failure = describeError(decide.error)

  /**
   * Back to the invoice, with the payment named so the invoice screen can look
   * it up. The id in the URL is a lookup key and never a claim: the invoice
   * shows the platform's own record of that payment.
   */
  function finish(payment: { id: string; invoice_id: string | null }): void {
    void navigate(
      payment.invoice_id === null
        ? '/invoices'
        : `/invoices/${payment.invoice_id}?payment=${encodeURIComponent(payment.id)}`,
    )
  }

  if (isPending) return <Loading className="py-12" />

  if (data === undefined) {
    return (
      <>
        <PageHeader title={t('gateway.title')} />
        <LoadFailure error={error} />
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('gateway.title')} description={t('gateway.subtitle')} />

      <div className="max-w-md">
        <Card title={t('gateway.authorisePayment')}>
          <div className="flex flex-col gap-4">
            <Alert tone="warning">{t('gateway.notARealProvider')}</Alert>

            <dl className="flex flex-col gap-2 text-sm">
              <div className="flex items-center justify-between gap-4">
                <dt className="text-[var(--text-muted)]">{t('gateway.amount')}</dt>
                <dd>
                  <MoneyText value={data.payment.amount} />
                </dd>
              </div>
              <div className="flex items-center justify-between gap-4">
                <dt className="text-[var(--text-muted)]">{t('gateway.reference')}</dt>
                <dd className="technical text-xs break-all" dir="ltr">
                  {data.reference}
                </dd>
              </div>
            </dl>

            {failure !== null ? (
              <Alert tone="error" requestId={failure.requestId}>
                {failure.message}
              </Alert>
            ) : null}

            <div className="flex flex-wrap gap-2">
              <Button
                loading={decide.isPending && decide.variables.decision === 'approve'}
                onClick={() => {
                  decide.mutate({ reference, decision: 'approve' }, { onSuccess: finish })
                }}
              >
                {t('gateway.approve')}
              </Button>

              <Button
                variant="danger"
                loading={decide.isPending && decide.variables.decision === 'decline'}
                onClick={() => {
                  decide.mutate({ reference, decision: 'decline' }, { onSuccess: finish })
                }}
              >
                {t('gateway.decline')}
              </Button>
            </div>

            <p className="text-xs text-[var(--text-muted)]">{t('gateway.settlementNote')}</p>
          </div>
        </Card>
      </div>
    </>
  )
}
