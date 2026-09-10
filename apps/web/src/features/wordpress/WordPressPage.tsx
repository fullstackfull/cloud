import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useOrderWordPressSite, useWordPressSites } from '@/lib/queries'
import type { WordPressSite } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { SiteAdvice, SiteSteps } from './SiteSteps'

/**
 * The WordPress sites this account has: an index, since Wave 3.
 *
 * Each card still answers "where has this got to" — the four steps and the one
 * sentence of advice, from the same components the site's own page uses — and
 * now links to the site instead of carrying every capability inline. The
 * copies, the push back and the operation history moved to the site's page,
 * which is where somebody working on one site is.
 */
export function WordPressPage() {
  const { t } = useTranslation()

  const { data, isPending, error: readError } = useWordPressSites()
  const rows = data?.data ?? []

  const [open, setOpen] = useState(false)

  return (
    <>
      <PageHeader title={t('nav.wordpress')} description={t('wordpress.subtitle')} />

      <LoadFailure error={readError} />

      <div className="mb-4">
        {open ? (
          <OrderForm onDone={() => { setOpen(false) }} />
        ) : (
          <Button onClick={() => { setOpen(true) }}>{t('wordpress.newSite')}</Button>
        )}
      </div>

      {isPending ? (
        <Loading />
      ) : rows.length === 0 ? (
        <EmptyState>{t('wordpress.none')}</EmptyState>
      ) : (
        <div className="flex flex-col gap-4">
          {rows.map((site) => (
            <SiteCard key={site.id} site={site} />
          ))}
        </div>
      )}

      {rows.length === 0 ? null : (
        <p className="mt-4 text-sm text-[var(--text-muted)]">{t('wordpress.verifiedExplainer')}</p>
      )}
    </>
  )
}

/** Asking for a site: a name, where it comes from, and who runs it. */
function OrderForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const order = useOrderWordPressSite()

  const [fields, setFields] = useState({
    domain: '',
    domain_source: 'external',
    admin_username: '',
    admin_email: '',
  })

  const failure = describeError(order.error)

  const set = (key: keyof typeof fields) => (event: { target: { value: string } }) => {
    setFields((current) => ({ ...current, [key]: event.target.value }))
  }

  return (
    <Card>
      <h2 className="text-sm font-medium">{t('wordpress.newSite')}</h2>

      <form
        className="mt-3 grid gap-3 sm:grid-cols-2"
        onSubmit={(event) => {
          event.preventDefault()
          order.mutate(fields, { onSuccess: onDone })
        }}
      >
        <Field
          label={t('wordpress.domain')}
          dir="ltr"
          placeholder="example.com"
          value={fields.domain}
          onChange={set('domain')}
          required
        />

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium">{t('wordpress.domainSource')}</span>
          <select
            value={fields.domain_source}
            onChange={set('domain_source')}
            className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
          >
            {/*
              * Four options because there are four, and each one changes what
              * the customer will be waiting for. A default of "external" is
              * the honest one: it promises the least.
              */}
            <option value="external">{t('wordpress.sources.external')}</option>
            <option value="existing">{t('wordpress.sources.existing')}</option>
            <option value="register">{t('wordpress.sources.register')}</option>
            <option value="transfer">{t('wordpress.sources.transfer')}</option>
          </select>
          <span className="text-sm text-[var(--text-muted)]">{t('wordpress.domainSourceHint')}</span>
        </label>

        <Field
          label={t('wordpress.adminUsername')}
          dir="ltr"
          value={fields.admin_username}
          onChange={set('admin_username')}
          hint={t('wordpress.adminUsernameHint')}
          required
        />

        <Field
          label={t('wordpress.adminEmail')}
          type="email"
          dir="ltr"
          value={fields.admin_email}
          onChange={set('admin_email')}
          required
        />

        <div className="sm:col-span-2 flex gap-3">
          <Button type="submit" loading={order.isPending}>
            {t('wordpress.create')}
          </Button>
          <Button type="button" variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
        </div>
      </form>

      <p className="mt-3 text-sm text-[var(--text-muted)]">{t('wordpress.passwordExplainer')}</p>

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

/** One site in the index: what it is, where it has got to, and a way in. */
function SiteCard({ site }: { site: WordPressSite }) {
  const { t } = useTranslation()

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="technical text-sm font-medium" dir="ltr">
            <Link to={`/wordpress/${site.id}`} className="hover:underline">
              {site.domain}
            </Link>
          </h2>
          {site.kind === 'production' ? null : (
            <Badge tone={site.kind === 'staging' ? 'info' : 'neutral'}>
              {t(`wordpress.kinds.${site.kind}`)}
            </Badge>
          )}
        </div>

        <div className="flex items-center gap-3">
          <StatusBadge status={site.state} />
          <Link to={`/wordpress/${site.id}`} className="text-sm underline">
            {t('resource.open')}
          </Link>
        </div>
      </div>

      <div className="mt-3">
        <SiteSteps site={site} />
      </div>

      <div className="mt-3">
        <SiteAdvice site={site} />
      </div>
    </Card>
  )
}
