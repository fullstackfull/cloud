import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useCountryCurrencyChanges,
  useReanalyseCountryCurrencyChange,
  useRequestCountryCurrencyChange,
  useWithdrawCountryCurrencyChange,
} from '@/lib/queries'
import type { CustomerSummary } from '@/features/auth/useAuth'
import type { CountryCurrencyChange } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * What the account is billed in, and the request to change it.
 *
 * Not a form that saves. The customer asks; the platform says what the
 * change would touch and what must be cleared first, in words; a person
 * approves. The sentence that matters is said before the form: nothing
 * already issued is ever converted. A screen that let a customer flip the
 * currency would be a screen that repriced their renewals without saying so.
 */
export function CountryCurrencySection({ customer }: { customer: CustomerSummary }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data, isPending } = useCountryCurrencyChanges()
  const request = useRequestCountryCurrencyChange()
  const recheck = useReanalyseCountryCurrencyChange()
  const withdraw = useWithdrawCountryCurrencyChange()

  const [asking, setAsking] = useState(false)
  const [country, setCountry] = useState(customer.country ?? '')
  const [currency, setCurrency] = useState(customer.currency)
  const [reason, setReason] = useState('')

  const requestFailure = describeError(request.error)
  const actionFailure = describeError(recheck.error ?? withdraw.error)

  const rows = data?.data ?? []
  const open = rows.find((row) => row.is_open) ?? null
  const history = rows.filter((row) => !row.is_open)
  const currencies = data?.meta.currencies ?? []

  const where = (code: string | null) => (code === null || code === '' ? '' : ` · ${code}`)

  return (
    <Card title={t('account.countryCurrency.title')} description={t('account.countryCurrency.subtitle')}>
      <p className="technical text-sm" dir="ltr" data-testid="billed-in">
        {t('account.countryCurrency.current', { currency: customer.currency, country: where(customer.country) })}
      </p>
      <p className="mt-2 text-sm text-[var(--text-muted)]">{t('account.countryCurrency.policy')}</p>

      {isPending ? (
        <p className="py-4 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
      ) : open !== null ? (
        <ChangeRow
          change={open}
          heading={t('account.countryCurrency.open')}
          onRecheck={() => { recheck.mutate(open.id) }}
          onWithdraw={() => { withdraw.mutate(open.id) }}
          busy={recheck.isPending || withdraw.isPending}
        />
      ) : asking ? (
        <form
          className="mt-4 flex flex-col gap-3"
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            request.mutate(
              { country: country.trim() === '' ? null : country.trim().toUpperCase(), currency, reason },
              { onSuccess: () => { setAsking(false); setReason('') } },
            )
          }}
        >
          <Field
            label={t('account.countryCurrency.country')}
            dir="ltr"
            maxLength={2}
            value={country}
            onChange={(event) => { setCountry(event.target.value.toUpperCase()) }}
            hint={t('account.countryCurrency.countryHint')}
            error={requestFailure?.fields?.['country']?.[0]}
          />

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium">{t('account.countryCurrency.currency')}</span>
            <select
              value={currency}
              onChange={(event) => { setCurrency(event.target.value) }}
              dir="ltr"
              className="technical h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              {[...new Set([customer.currency, ...currencies])].map((code) => (
                <option key={code} value={code}>
                  {code}
                </option>
              ))}
            </select>
          </label>

          <Field
            label={t('account.countryCurrency.reason')}
            value={reason}
            onChange={(event) => { setReason(event.target.value) }}
            hint={t('account.countryCurrency.reasonHint')}
            required
            error={requestFailure?.fields?.['reason']?.[0]}
          />

          {requestFailure === null || requestFailure.fields !== null ? null : (
            <Alert tone="error" requestId={requestFailure.requestId}>
              {requestFailure.message}
            </Alert>
          )}

          <div className="flex gap-2">
            <Button type="submit" loading={request.isPending}>
              {t('account.countryCurrency.submit')}
            </Button>
            <Button type="button" variant="ghost" onClick={() => { setAsking(false); request.reset() }}>
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      ) : (
        <div className="mt-4">
          {request.isSuccess ? (
            <div className="mb-3">
              <Alert tone="success">{t('account.countryCurrency.requested')}</Alert>
            </div>
          ) : null}
          <Button variant="secondary" onClick={() => { setAsking(true) }}>
            {t('account.countryCurrency.request')}
          </Button>
        </div>
      )}

      {actionFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={actionFailure.requestId}>
            {actionFailure.message}
          </Alert>
        </div>
      )}

      {history.length === 0 ? null : (
        <div className="mt-5 border-t border-[var(--border-subtle)] pt-4">
          <h3 className="text-sm font-medium">{t('account.countryCurrency.history')}</h3>
          <ul className="mt-2 flex flex-col gap-2">
            {history.slice(0, 5).map((row) => (
              <li key={row.id} className="flex flex-wrap items-center gap-2 text-sm">
                <StatusBadge status={row.state} />
                <span className="technical text-xs" dir="ltr">
                  {t('account.countryCurrency.to', { currency: row.to_currency, country: where(row.to_country) })}
                </span>
                <span className="text-xs text-[var(--text-muted)]">
                  {row.applied_at !== null
                    ? t('account.countryCurrency.appliedAt', { date: formatDateTime(row.applied_at, locale) })
                    : formatDateTime(row.decided_at ?? row.created_at, locale)}
                </span>
                {row.decision_note === null ? null : (
                  <span className="text-xs text-[var(--text-muted)]">
                    {t('account.countryCurrency.note')}: {row.decision_note}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  )
}

function ChangeRow({
  change,
  heading,
  onRecheck,
  onWithdraw,
  busy,
}: {
  change: CountryCurrencyChange
  heading: string
  onRecheck: () => void
  onWithdraw: () => void
  busy: boolean
}) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const where = (code: string | null) => (code === null || code === '' ? '' : ` · ${code}`)

  return (
    <div className="mt-4 rounded-lg border border-[var(--border-subtle)] p-3" data-testid="country-currency-change">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-sm font-medium">{heading}</h3>
          <StatusBadge status={change.state} />
        </div>
        <span className="technical text-sm" dir="ltr">
          {t('account.countryCurrency.to', { currency: change.to_currency, country: where(change.to_country) })}
        </span>
      </div>

      <p className="mt-2 text-sm text-[var(--text-muted)]">
        {change.state === 'blocked'
          ? t('account.countryCurrency.blocked')
          : change.state === 'awaiting_approval'
            ? t('account.countryCurrency.awaiting')
            : change.state === 'scheduled' && change.scheduled_for !== null
              ? t('account.countryCurrency.scheduledFor', { date: formatDateTime(change.scheduled_for, locale) })
              : null}
      </p>

      {change.needs_attention ? (
        <div className="mt-2">
          <Alert tone="warning">{t('account.countryCurrency.needsReview')}</Alert>
        </div>
      ) : null}

      {change.impact.blockers.length === 0 ? null : (
        <div className="mt-3">
          <h4 className="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
            {t('account.countryCurrency.blockers')}
          </h4>
          <ul className="mt-1 list-disc ps-5 text-sm" aria-label={t('account.countryCurrency.blockers')}>
            {change.impact.blockers.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        </div>
      )}

      {change.impact.warnings.length === 0 ? null : (
        <div className="mt-3">
          <h4 className="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
            {t('account.countryCurrency.warnings')}
          </h4>
          <ul className="mt-1 list-disc ps-5 text-sm text-[var(--text-muted)]" aria-label={t('account.countryCurrency.warnings')}>
            {change.impact.warnings.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="mt-3 flex flex-wrap gap-2">
        {change.state === 'blocked' || change.state === 'awaiting_approval' ? (
          <Button size="sm" variant="secondary" loading={busy} onClick={onRecheck}>
            {t('account.countryCurrency.recheck')}
          </Button>
        ) : null}
        <Button size="sm" variant="ghost" disabled={busy} onClick={onWithdraw}>
          {t('account.countryCurrency.withdraw')}
        </Button>
      </div>
    </div>
  )
}
