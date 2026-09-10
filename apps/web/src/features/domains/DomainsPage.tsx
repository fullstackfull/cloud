import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { formatDate, formatMinorUnits } from '@/lib/format'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import {
  useDomainAuthorisationCode,
  useDomains,
  useDomainSearch,
  useOrderDomain,
  useQuoteDomain,
  useRedeemDomain,
  useSetDomainNameservers,
  useSetDomainTransferLock,
} from '@/lib/queries'
import type { Locale } from '@/i18n'
import type { Domain, DomainQuote, DomainSearchResult } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Loading } from '@/components/Loading'
import { Link } from 'react-router'

/**
 * Buying a name, and looking after the ones already bought.
 *
 * Two things on this screen are load-bearing and easy to design away.
 *
 * **The fifth answer.** A search returns five availability states, and
 * `unknown` — the registrar did not answer — is rendered as itself. It would
 * be less cluttered to fold it into "taken", and that would tell a customer a
 * name they could have had is gone. It would be friendlier to fold it into
 * "available", and that would sell them a name somebody else owns.
 *
 * **The price is not the customer's.** Nothing here posts an amount. The
 * search shows the catalogue's number, the order sends a quote id, and the
 * server re-reads what it wrote. On a premium name the difference between
 * those two designs is a hundredfold.
 */
export function DomainsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data, isPending, error: readError } = useDomains()
  const rows = data?.data ?? []

  const [typed, setTyped] = useState('')
  const [searching, setSearching] = useState('')
  const search = useDomainSearch(searching)

  const searchFailure = describeError(search.error)

  return (
    <>
      <PageHeader title={t('nav.domains')} description={t('domains.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            setSearching(typed.trim())
          }}
        >
          <div className="min-w-[16rem] flex-1">
            <Field
              label={t('domains.searchLabel')}
              dir="ltr"
              placeholder="example.com"
              value={typed}
              onChange={(event) => { setTyped(event.target.value) }}
              hint={t('domains.searchHint')}
            />
          </div>

          <Button type="submit" loading={search.isFetching}>
            {t('domains.search')}
          </Button>
        </form>

        {searchFailure === null ? null : (
          <div className="mt-3">
            <Alert tone="error" requestId={searchFailure.requestId}>
              {searchFailure.message}
            </Alert>
          </div>
        )}

        {search.data === undefined ? null : (
          <div className="mt-4">
            <SearchResults results={search.data.data} />
          </div>
        )}
      </Card>

      <div className="mt-4">
        {isPending ? (
          <Loading />
        ) : rows.length === 0 ? (
          <EmptyState>{t('domains.none')}</EmptyState>
        ) : (
          <div className="flex flex-col gap-4">
            {rows.map((domain) => (
              <DomainCard key={domain.id} domain={domain} locale={locale} />
            ))}
          </div>
        )}
      </div>
    </>
  )
}

/**
 * Tones for the five answers.
 *
 * `unknown` is amber rather than grey: it is the one that needs the customer
 * to notice it, because it is the one where trying again is the right move and
 * acting on it is not.
 */
const AVAILABILITY_TONES: Record<
  DomainSearchResult['availability'],
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  available: 'success',
  premium: 'info',
  unavailable: 'neutral',
  unknown: 'warning',
  unsupported: 'neutral',
}

/**
 * What the registrar said, per namespace.
 *
 * The buy button is bound to `is_orderable` rather than to the availability
 * string, so a state the portal has not been taught cannot become a purchase
 * the platform will refuse.
 */
function SearchResults({ results }: { results: DomainSearchResult[] }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()

  const [buying, setBuying] = useState<DomainSearchResult | null>(null)

  const columns: Column<DomainSearchResult>[] = [
    {
      key: 'name',
      header: t('domains.name'),
      cell: (row) => <span className="technical" dir="ltr">{row.name}</span>,
    },
    {
      key: 'availability',
      header: t('domains.availability'),
      /*
       * Its own words rather than the shared status vocabulary. "Unknown" is
       * accurate and useless on a search result — the customer wants to know
       * the registry did not answer and that trying again may work, not that
       * something is in an unknown state.
       */
      cell: (row) => (
        <Badge tone={AVAILABILITY_TONES[row.availability]}>
          {t(`domains.availabilityStates.${row.availability}`)}
        </Badge>
      ),
    },
    {
      key: 'price',
      header: t('domains.price'),
      cell: (row) =>
        row.price_minor === null || row.currency === null ? (
          // Not a zero and not a dash-shaped guess: the platform has not
          // committed to a number for this name.
          <span className="text-sm text-[var(--text-muted)]">{t('domains.noPrice')}</span>
        ) : (
          <span className="technical">
            {formatMinorUnits(row.price_minor, row.currency, locale)}
            {row.premium ? ` · ${t('domains.premium')}` : ''}
          </span>
        ),
    },
    {
      key: 'action',
      header: '',
      cell: (row) =>
        row.is_orderable ? (
          <Button onClick={() => { setBuying(row) }}>{t('domains.buy')}</Button>
        ) : row.availability === 'unknown' ? (
          <span className="text-sm text-[var(--text-muted)]">{t('domains.unknownHint')}</span>
        ) : null,
    },
  ]

  return (
    <>
      <DataTable
        columns={columns}
        rows={results}
        rowKey={(row) => row.name}
        empty={t('domains.noResults')}
      />

      {buying === null ? null : (
        <RegistrationForm result={buying} onDone={() => { setBuying(null) }} />
      )}
    </>
  )
}

/**
 * The registrant, and then a quote, and then an order.
 *
 * The quote is taken at submit rather than when the form opens, so that a
 * customer who leaves the tab open over lunch is charged today's price rather
 * than told their quote expired.
 */
function RegistrationForm({
  result,
  onDone,
}: {
  result: DomainSearchResult
  onDone: () => void
}) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const quote = useQuoteDomain()
  const order = useOrderDomain()

  const [fields, setFields] = useState({
    name: '',
    email: '',
    phone: '',
    address_line_one: '',
    city: '',
    country: '',
  })

  const failure = describeError(order.error) ?? describeError(quote.error)

  const set = (key: keyof typeof fields) => (event: { target: { value: string } }) => {
    setFields((current) => ({ ...current, [key]: event.target.value }))
  }

  return (
    <Card>
      <h2 className="text-sm font-medium">
        {t('domains.registerTitle', { domain: result.name })}
      </h2>

      <p className="mt-1 text-sm text-[var(--text-muted)]">{t('domains.registrantExplainer')}</p>

      <form
        className="mt-3 grid gap-3 sm:grid-cols-2"
        onSubmit={(event) => {
          event.preventDefault()

          quote.mutate(
            { name: result.name, operation: 'register' },
            {
              onSuccess: (quoted) => {
                order.mutate(
                  { quote_id: quoted.data.id, registrant: fields },
                  { onSuccess: onDone },
                )
              },
            },
          )
        }}
      >
        <Field label={t('domains.registrantName')} value={fields.name} onChange={set('name')} required />
        <Field label={t('domains.registrantEmail')} type="email" dir="ltr" value={fields.email} onChange={set('email')} required />
        <Field label={t('domains.registrantPhone')} dir="ltr" value={fields.phone} onChange={set('phone')} required />
        <Field label={t('domains.registrantAddress')} value={fields.address_line_one} onChange={set('address_line_one')} required />
        <Field label={t('domains.registrantCity')} value={fields.city} onChange={set('city')} required />
        <Field label={t('domains.registrantCountry')} dir="ltr" placeholder="KW" value={fields.country} onChange={set('country')} required />

        <div className="sm:col-span-2 flex gap-3">
          <Button type="submit" loading={quote.isPending || order.isPending}>
            {t('domains.confirmOrder')}
          </Button>
          <Button type="button" variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
        </div>
      </form>

      <p className="mt-3 text-sm text-[var(--text-muted)]">{t('domains.paymentExplainer')}</p>

      {failure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      )}
    </Card>
  )
}

/** One held name: what it costs to keep, where it points, and how to leave. */
function DomainCard({ domain, locale }: { domain: Domain; locale: Locale }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const nameservers = useSetDomainNameservers(domain.id)
  const lock = useSetDomainTransferLock(domain.id)
  const authCode = useDomainAuthorisationCode(domain.id)

  const [hosts, setHosts] = useState(domain.nameservers.join('\n'))

  const failure =
    describeError(nameservers.error) ?? describeError(lock.error) ?? describeError(authCode.error)

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="technical text-sm font-medium" dir="ltr">
          {domain.name}
        </h2>
        <StatusBadge status={domain.state} />
      </div>

      <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-[var(--text-muted)]">{t('domains.expires')}</dt>
          <dd>
            {domain.expires_at === null ? '—' : formatDate(domain.expires_at, locale)}
          </dd>
        </div>
        <div>
          <dt className="text-[var(--text-muted)]">{t('domains.autoRenew')}</dt>
          <dd>{domain.auto_renew ? t('common.yes') : t('common.no')}</dd>
        </div>
        <div>
          <dt className="text-[var(--text-muted)]">{t('domains.transferLock')}</dt>
          <dd>{domain.transfer_locked === true ? t('common.yes') : t('common.no')}</dd>
        </div>
      </dl>

      {domain.needs_attention ? (
        <div className="mt-3">
          {/*
            * The Timeout Rule reaching the customer. A name the platform could
            * not confirm must not be presented as working, and must not offer
            * a retry: the money may already have moved.
            */}
          <Alert tone="warning">{t('domains.needsAttention')}</Alert>
        </div>
      ) : null}

      {domain.is_expiring ? (
        <div className="mt-3">
          <Alert tone="warning">{t('domains.expiringSoon')}</Alert>
        </div>
      ) : null}

      {domain.redemption === null ? null : <RedemptionPanel domain={domain} locale={locale} />}

      {domain.is_manageable ? (
        <>
          <form
            className="mt-4 flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault()

              nameservers.mutate({
                nameservers: hosts
                  .split(/[\s,]+/)
                  .map((host) => host.trim())
                  .filter((host) => host !== ''),
              })
            }}
          >
            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium">{t('domains.nameservers')}</span>
              <textarea
                dir="ltr"
                rows={3}
                value={hosts}
                onChange={(event) => { setHosts(event.target.value) }}
                className="technical rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 py-2 text-sm"
              />
            </label>
            <span className="text-sm text-[var(--text-muted)]">{t('domains.nameserversHint')}</span>

            <div>
              <Button type="submit" loading={nameservers.isPending}>
                {t('common.save')}
              </Button>
            </div>
          </form>

          <div className="mt-4 flex flex-wrap gap-3">
            <Button
              variant="secondary"
              loading={lock.isPending}
              onClick={() => { lock.mutate({ locked: domain.transfer_locked !== true }) }}
            >
              {domain.transfer_locked === true ? t('domains.unlock') : t('domains.lock')}
            </Button>

            <Button
              variant="secondary"
              loading={authCode.isPending}
              onClick={() => { authCode.mutate() }}
            >
              {t('domains.getAuthCode')}
            </Button>
          </div>

          <p className="mt-2 text-sm text-[var(--text-muted)]">{t('domains.leavingExplainer')}</p>

          {authCode.data === undefined ? null : (
            <div className="mt-3">
              <Alert tone="info">
                <span className="technical" dir="ltr">
                  {authCode.data.data.authorisation_code}
                </span>
              </Alert>
              <p className="mt-1 text-sm text-[var(--text-muted)]">{t('domains.authCodeHint')}</p>
            </div>
          )}
        </>
      ) : null}

      {failure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      )}
    </Card>
  )
}

/**
 * A lapsed name and the one way back.
 *
 * Every state the addendum lists is a sentence here — expired, in
 * redemption, recovery available or not (and why), the price, the quote's
 * expiry, awaiting payment, provider processing, needs review, recovered —
 * and none of them is urgent-sounding. The registry's clock is real; a
 * countdown would be theatre.
 */
function RedemptionPanel({ domain, locale }: { domain: Domain; locale: Locale }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const quote = useQuoteDomain()
  const redeem = useRedeemDomain(domain.id)
  const [pending, setPending] = useState<DomainQuote | null>(null)
  const redemption = domain.redemption
  if (redemption === null) return null

  const attempt = redemption.attempt
  const inFlight = attempt !== null && ['requested', 'queued', 'running', 'awaiting_registry', 'indeterminate', 'needs_review'].includes(attempt.state)
  const failure = describeError(quote.error) ?? describeError(redeem.error)

  return (
    <section className="mt-4 flex flex-col gap-2 rounded-lg border border-[var(--border-subtle)] p-3" aria-label={t('domains.redemption.heading')}>
      <h3 className="text-sm font-semibold">{t('domains.redemption.heading')}</h3>

      {domain.state === 'redemption' ? (
        <p className="text-sm text-[var(--text-secondary)]">
          {t('domains.redemption.lapsed', { date: domain.expires_at === null ? '' : formatDate(domain.expires_at, locale) })}
        </p>
      ) : null}

      {attempt !== null && attempt.state === 'completed' ? <Alert tone="success">{t('domains.redemption.recovered')}</Alert> : null}
      {attempt !== null && (attempt.state === 'requested' || attempt.state === 'queued') ? (
        <Alert tone="info">
          {t('domains.redemption.awaitingPayment')}{' '}
          {attempt.invoice_id === null ? null : (
            <Link to="/invoices" className="underline underline-offset-2">{t('domains.redemption.openInvoice')}</Link>
          )}
        </Alert>
      ) : null}
      {attempt !== null && (attempt.state === 'running' || attempt.state === 'awaiting_registry') ? <Alert tone="info">{t('domains.redemption.processing')}</Alert> : null}
      {attempt !== null && attempt.needs_attention ? <Alert tone="warning">{t('domains.redemption.needsReview')}</Alert> : null}
      {attempt !== null && attempt.state === 'failed' ? <Alert tone="error">{t('domains.redemption.failed')}</Alert> : null}

      {domain.is_redeemable && !inFlight ? (
        redemption.support === 'supported' && redemption.price_minor !== null && redemption.currency !== null ? (
          <>
            <p className="text-sm">{t('domains.redemption.available', { price: formatMinorUnits(redemption.price_minor, redemption.currency, locale) })}</p>
            <div>
              <Button
                loading={quote.isPending}
                onClick={() => {
                  quote.mutate({ name: domain.name, operation: 'redeem' }, { onSuccess: (result) => { setPending(result.data) } })
                }}
              >
                {t('domains.redemption.recover')}
              </Button>
            </div>
          </>
        ) : (
          <Alert tone="warning">{t(`domains.redemption.unavailable.${redemption.support === 'supported' ? 'blocked_configuration' : redemption.support}`)}</Alert>
        )
      ) : null}

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>{failure.message}</Alert>
      )}

      {pending === null ? null : (
        <ConfirmDialog
          open
          title={t('domains.redemption.quoteTitle', { domain: domain.name })}
          body={
            <div className="flex flex-col gap-2 text-sm">
              <p>{t('domains.redemption.quoteBody')}</p>
              <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
                <dt className="text-[var(--text-muted)]">{t('domains.redemption.quotePrice')}</dt>
                <dd className="technical">{formatMinorUnits(pending.price_minor, pending.currency, locale)}</dd>
              </dl>
              <p className="text-[var(--text-muted)]">{t('domains.redemption.quoteExpires', { when: formatDate(pending.expires_at, locale) })}</p>
              <p className="text-[var(--text-muted)]">{t('domains.redemption.paymentExplainer')}</p>
            </div>
          }
          confirmLabel={t('domains.redemption.confirm')}
          loading={redeem.isPending}
          error={describeError(redeem.error)?.message}
          onCancel={() => { setPending(null); redeem.reset() }}
          onConfirm={() => { redeem.mutate({ quote_id: pending.id }, { onSuccess: () => { setPending(null) } }) }}
        />
      )}
    </section>
  )
}
