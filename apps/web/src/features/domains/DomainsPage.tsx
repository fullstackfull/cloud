import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate, formatMinorUnits } from '@/lib/format'
import {
  useDomains,
  useDomainSearch,
  useOrderDomain,
  useQuoteDomain,
} from '@/lib/queries'
import type { Domain, DomainSearchResult } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { DomainTransferInForm } from './DomainTransferInForm'

/**
 * Buying a name, bringing one in, and getting to the ones already held.
 *
 * Since Wave 3 the held names are an index: a row identifies the name, says
 * whether it needs attention and links to it. Everything that can be done to
 * one name — nameservers, contacts, auto-renew, renewing now, the transfer
 * lock, the authorisation code, recovery of a lapsed name — moved to that
 * name's own page, where somebody working on one name is.
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
  const [transferring, setTransferring] = useState(false)
  const search = useDomainSearch(searching)

  const searchFailure = describeError(search.error)

  const columns: Array<Column<Domain>> = [
    {
      key: 'name',
      header: t('domains.name'),
      ltr: true,
      cell: (domain) => (
        <Link
          to={`/domains/${encodeURIComponent(domain.name)}`}
          className="technical font-medium text-[var(--text-primary)] hover:underline"
        >
          {domain.name}
        </Link>
      ),
    },
    {
      key: 'state',
      header: t('services.state'),
      cell: (domain) => (
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={domain.state} />
          {domain.needs_attention ? (
            <Badge tone="warning">{t('domains.attention')}</Badge>
          ) : domain.is_expiring ? (
            <Badge tone="warning">{t('domains.expiringSoon')}</Badge>
          ) : null}
        </div>
      ),
    },
    {
      key: 'expires',
      header: t('domains.expires'),
      cell: (domain) =>
        domain.expires_at === null ? '—' : formatDate(domain.expires_at, locale),
    },
    {
      key: 'autoRenew',
      header: t('domains.autoRenew'),
      cell: (domain) => (domain.auto_renew ? t('common.yes') : t('common.no')),
    },
    {
      key: 'actions',
      header: '',
      cell: (domain) => (
        <div className="flex justify-end">
          <Link to={`/domains/${encodeURIComponent(domain.name)}`} className="text-sm underline">
            {t('resource.open')}
          </Link>
        </div>
      ),
    },
  ]

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
        {transferring ? (
          <DomainTransferInForm onDone={() => { setTransferring(false) }} />
        ) : (
          <Button variant="secondary" onClick={() => { setTransferring(true) }}>
            {t('domains.transferIn.action')}
          </Button>
        )}
      </div>

      <div className="mt-4">
        {isPending ? (
          <Loading />
        ) : rows.length === 0 ? (
          <EmptyState>{t('domains.none')}</EmptyState>
        ) : (
          <Card title={t('domains.held')} description={t('domains.heldBody')}>
            <DataTable
              caption={t('domains.held')}
              columns={columns}
              rows={rows}
              rowKey={(domain) => domain.id}
              empty={t('domains.none')}
            />
          </Card>
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
