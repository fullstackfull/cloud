import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { CheckboxField } from '@/components/CheckboxField'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { pathForResource } from '@/features/resources/resourcePaths'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useCancelSubscription } from '@/lib/queries'
import type { Subscription, SubscriptionService } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The one thing a customer needs to tell two subscriptions apart: the plan,
 * the product it belongs to, and the machine or domain it runs.
 *
 * Every part comes from the server. `identity` is null while a service is
 * still being created, and the screen says that rather than showing a
 * placeholder that looks like a hostname.
 */
function ServiceIdentityText({ service }: { service: SubscriptionService }) {
  const name = (
    <span className="technical" dir="ltr">
      {service.identity}
    </span>
  )

  const to = service.resource === null
    ? null
    : pathForResource(service.resource.kind, service.resource.id)

  return to === null ? name : (
    <Link to={to} className="hover:underline">
      {name}
    </Link>
  )
}

export function SubscriptionIdentity({ subscription }: { subscription: Subscription }) {
  const { t } = useTranslation()

  const services = subscription.services ?? []
  const planName = subscription.plan?.name ?? null
  const productName = subscription.product?.name ?? null

  return (
    <div className="flex flex-col gap-0.5">
      <span className="font-medium text-[var(--text-primary)]">
        {planName ?? t('subscriptions.unnamedPlan')}
      </span>

      {productName !== null ? (
        <span className="text-xs text-[var(--text-muted)]">{productName}</span>
      ) : null}

      {services.length === 0 ? (
        <span className="text-xs text-[var(--text-muted)]">{t('subscriptions.noService')}</span>
      ) : (
        services.map((service) => (
          <span key={service.id} className="text-xs text-[var(--text-secondary)]">
            {service.identity === null ? (
              t('subscriptions.serviceBeingCreated')
            ) : (
              /*
               * A link to the thing itself where the API published a handle
               * for it. The id in that handle is the machine's or the
               * account's, not the service's — building one from the service
               * id would send a customer to a page for something that does
               * not exist.
               */
              <ServiceIdentityText service={service} />
            )}
          </span>
        ))
      )}
    </div>
  )
}

/**
 * Ending a subscription, from wherever the customer is standing.
 *
 * Extracted in Wave 3 because the resource pages now offer the same act from
 * the machine, the site or the account it pays for. Two copies of a dialogue
 * that decides when somebody's data is destroyed would be two chances to get
 * the sentence about the retention window wrong — and the audit's own warning
 * about resource pages was exactly this: two UI paths must not become two
 * implementations.
 *
 * The mutation, the wording, the typed evidence and the retention sentence all
 * live here; the caller supplies the subscription and is told when it closes.
 */
export function CancelSubscriptionDialog({
  subscription,
  onClose,
}: {
  subscription: Subscription
  onClose: () => void
}) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()
  const cancel = useCancelSubscription()
  const [immediately, setImmediately] = useState(false)

  const cancelFailure = describeError(cancel.error)

  function close() {
    setImmediately(false)
    cancel.reset()
    onClose()
  }

  return (
    <>
    <ConfirmDialog
          open
          title={t('subscriptions.cancelTitle')}
          body={
            <div className="flex flex-col gap-2">
              {/*
                * Which subscription this is, spelled out inside the dialogue.
                * A confirmation that says only "cancel this subscription" is
                * a confirmation of nothing in particular.
                */}
              <div className="rounded-lg border border-[var(--border-subtle)] p-3">
                <SubscriptionIdentity subscription={subscription} />
              </div>

              <p>
                {immediately
                  ? t('subscriptions.cancelNowWarning')
                  : t('subscriptions.cancelWarning', {
                      date:
                        subscription.current_period_end === null
                          ? '—'
                          : formatDate(subscription.current_period_end, locale),
                    })}
              </p>

              {/* The second date, which is the one customers ring up about. */}
              <p>
                {t('subscriptions.dataWarning', { days: subscription.data_retention_days ?? 0 })}
              </p>

              <div className="mt-1">
                <CheckboxField
                  label={t('subscriptions.endNowOption', {
                    date:
                      subscription.current_period_end === null
                        ? '—'
                        : formatDate(subscription.current_period_end, locale),
                  })}
                  checked={immediately}
                  onChange={(event) => { setImmediately(event.target.checked) }}
                />
              </div>

              {immediately ? (
                <p className="technical text-xs break-all select-all" dir="ltr">
                  {subscription.id}
                </p>
              ) : null}
            </div>
          }
          /*
           * Spread rather than passed as undefined: under
           * exactOptionalPropertyTypes an optional prop must be absent, and
           * the distinction is the whole guard here — a ConfirmDialog with no
           * requiredPhrase confirms on one click.
           */
          {...(immediately
            ? {
                requiredPhrase: subscription.id,
                requiredPhraseLabel: t('subscriptions.cancelConfirmLabel'),
              }
            : {})}
          /*
           * Never the word "Cancel". The dialogue's own dismiss button says
           * that, and in a dialogue it means "do not do this" — two buttons
           * reading Cancel, one of which ends the customer's service, is the
           * kind of thing somebody clicks once and remembers for years.
           */
          confirmLabel={
            immediately ? t('subscriptions.endNowConfirm') : t('subscriptions.confirmEnd')
          }
          loading={cancel.isPending}
          {...(cancelFailure === null ? {} : { error: cancelFailure.message })}
          onCancel={close}
          onConfirm={(confirmation) => {
            cancel.mutate(
              { id: subscription.id, immediately, ...(immediately ? { confirmation } : {}) },
              { onSuccess: close },
            )
          }}
        />

      {cancelFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={cancelFailure.requestId}>
            {cancelFailure.message}
          </Alert>
        </div>
      )}
    </>
  )
}
