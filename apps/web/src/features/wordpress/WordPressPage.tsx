import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useCloneWordPressSite,
  useCreateWordPressStaging,
  useOrderWordPressSite,
  usePushWordPressToProduction,
  useWordPressPushImpact,
  useWordPressSiteOperations,
  useWordPressSites,
} from '@/lib/queries'
import type { WordPressPushImpact, WordPressSite } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * WordPress sites, and what is actually true about each of them.
 *
 * ---------------------------------------------------------------------------
 * Four steps, shown as four steps
 * ---------------------------------------------------------------------------
 *
 * A site is a hosting account, a name pointed at it, a certificate, and an
 * installation that answers. Each finishes minutes or days apart, and the
 * tempting design — one spinner labelled "setting up" — is the one that fills
 * the support queue: every customer waiting on a different step asks the same
 * question, and the screen has told none of them anything.
 *
 * So the steps are drawn individually, and the advice under them changes with
 * the state. Somebody waiting on their own registrar is told to go and change
 * their DNS. Somebody waiting on a certificate is told their site is up.
 *
 * ---------------------------------------------------------------------------
 * "Live" is not a synonym for "finished building"
 * ---------------------------------------------------------------------------
 *
 * The tick beside a site means this platform fetched it and WordPress
 * answered. It is deliberately not shown for a site the installer merely
 * reported success on — that claim is worth nothing to a customer whose site
 * serves a database error, and they are the ones who would find out.
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

/** One site, drawn as the four things that have to be true. */
function SiteCard({ site }: { site: WordPressSite }) {
  const { t } = useTranslation()

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="technical text-sm font-medium" dir="ltr">
            {site.domain}
          </h2>
          {site.kind === 'production' ? null : (
            <Badge tone={site.kind === 'staging' ? 'info' : 'neutral'}>{t(`wordpress.kinds.${site.kind}`)}</Badge>
          )}
        </div>
        <StatusBadge status={site.state} />
      </div>

      <ul className="mt-3 flex flex-col gap-1.5 text-sm">
        <Step done={site.dns_ready} label={t('wordpress.steps.dns')} />
        <Step done={site.installed} label={t('wordpress.steps.installed')} />
        <Step done={site.ssl_status === 'active'} label={t('wordpress.steps.certificate')} />
        {/*
          * The last step is the platform's own: it fetched the site and
          * WordPress answered. The other three are reports from elsewhere and
          * all three can be true over a site that does not work.
          */}
        <Step done={site.is_verified} label={t('wordpress.steps.verified')} />
      </ul>

      {site.needs_attention ? (
        <div className="mt-3">
          <Alert tone="warning">
            {site.failure_reason ?? t('wordpress.needsAttention')}
          </Alert>
        </div>
      ) : site.state === 'awaiting_dns' ? (
        <div className="mt-3">
          {/*
            * The single most useful sentence on this page. A customer waiting
            * here is waiting on themselves, and a spinner would never tell
            * them that.
            */}
          <Alert tone="info">{t('wordpress.awaitingDnsHint')}</Alert>
        </div>
      ) : site.state === 'awaiting_certificate' ? (
        <div className="mt-3">
          <Alert tone="info">{t('wordpress.awaitingCertificateHint')}</Alert>
        </div>
      ) : null}

      {site.is_usable && site.admin_url !== null ? (
        <p className="mt-3 text-sm">
          <a className="technical underline" dir="ltr" href={site.admin_url} rel="noreferrer noopener" target="_blank">
            {site.admin_url}
          </a>
        </p>
      ) : null}

      <SiteCopies site={site} />
    </Card>
  )
}

/**
 * Copies of a site and the push back, on the site's own card.
 *
 * The buttons are there or not as the site's panel says, with the reason
 * when not: a toolkit that cannot copy is a button that honestly does not
 * exist. The push is the dangerous one and is arranged like every
 * destructive act here — what it overwrites is fetched and shown in words
 * before the production domain is asked to be typed back, and the words
 * include what this platform holds no backup of.
 */
function SiteCopies({ site }: { site: WordPressSite }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data: operations } = useWordPressSiteOperations(site.id)
  const staging = useCreateWordPressStaging()
  const clone = useCloneWordPressSite()
  const impact = useWordPressPushImpact()
  const push = usePushWordPressToProduction()

  const [cloning, setCloning] = useState(false)
  const [cloneDomain, setCloneDomain] = useState('')
  const [pushing, setPushing] = useState<{ scope: string; impact: WordPressPushImpact } | null>(null)

  const failure = describeError(staging.error ?? clone.error ?? impact.error)
  const pushFailure = describeError(push.error)

  const rows = operations?.data ?? []
  const canDoAnything = site.copies.staging || site.copies.clone || site.copies.push_to_production

  async function openPush(scope: string) {
    const answer = await impact.mutateAsync({ siteId: site.id, scope }).catch(() => null)

    if (answer !== null) {
      setPushing({ scope, impact: answer.data })
    }
  }

  return (
    <div className="mt-4 border-t border-[var(--border-subtle)] pt-3">
      {canDoAnything ? (
        <div className="flex flex-wrap items-center gap-2">
          {site.copies.staging ? (
            <Button size="sm" variant="secondary" loading={staging.isPending} onClick={() => { staging.mutate(site.id) }}>
              {t('wordpress.copies.staging')}
            </Button>
          ) : null}

          {site.copies.clone && !cloning ? (
            <Button size="sm" variant="ghost" onClick={() => { setCloning(true) }}>
              {t('wordpress.copies.clone')}
            </Button>
          ) : null}

          {site.copies.push_to_production ? (
            <Button size="sm" variant="danger" loading={impact.isPending} onClick={() => { void openPush('both') }}>
              {t('wordpress.push.action')}
            </Button>
          ) : null}
        </div>
      ) : site.is_usable && site.copies.reason !== null ? (
        <p className="text-xs text-[var(--text-muted)]">{t('wordpress.copies.unavailable', { reason: site.copies.reason })}</p>
      ) : null}

      {site.copies.staging ? (
        <p className="mt-1 text-xs text-[var(--text-muted)]">{t('wordpress.copies.stagingHint', { domain: site.domain })}</p>
      ) : null}

      {cloning ? (
        <form
          className="mt-3 flex flex-wrap items-end gap-2"
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            clone.mutate(
              { siteId: site.id, domain: cloneDomain.trim() },
              { onSuccess: () => { setCloning(false); setCloneDomain('') } },
            )
          }}
        >
          <div className="min-w-[14rem]">
            <Field
              label={t('wordpress.copies.cloneDomain')}
              dir="ltr"
              value={cloneDomain}
              onChange={(event) => { setCloneDomain(event.target.value) }}
              error={failure?.fields?.['domain']?.[0]}
            />
          </div>
          <Button type="submit" size="sm" loading={clone.isPending}>
            {t('wordpress.copies.cloneSubmit')}
          </Button>
          <Button type="button" size="sm" variant="ghost" onClick={() => { setCloning(false); clone.reset() }}>
            {t('common.cancel')}
          </Button>
        </form>
      ) : null}

      {staging.isSuccess || clone.isSuccess ? (
        <div className="mt-2">
          <Alert tone="info">{t('wordpress.copies.requested')}</Alert>
        </div>
      ) : null}

      {failure === null || failure.fields !== null ? null : (
        <div className="mt-2">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      )}

      {rows.length === 0 ? null : (
        <ul className="mt-3 flex flex-col gap-1.5" aria-label={t('wordpress.operations.title')}>
          {rows.slice(0, 5).map((op) => (
            <li key={op.id} className="flex flex-col gap-1 text-xs" data-testid="wordpress-operation">
              <div className="flex flex-wrap items-center gap-2">
                <span>{t(`wordpress.operations.kinds.${op.kind}`)}</span>
                <StatusBadge status={op.state} />
                <span className="text-[var(--text-muted)]">{formatDateTime(op.created_at, locale)}</span>
              </div>
              {op.needs_attention && op.kind === 'push_to_production' ? (
                <Alert tone="warning">{t('wordpress.push.needsReview')}</Alert>
              ) : op.failure_reason !== null && !op.needs_attention ? (
                <span className="text-[var(--text-muted)]">{op.failure_reason}</span>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {pushing === null ? null : (
        <ConfirmDialog
          open
          title={t('wordpress.push.title', { staging: site.domain, production: pushing.impact.production_domain })}
          body={
            <>
              <label className="flex flex-col gap-1.5 text-sm">
                <span className="font-medium">{t('wordpress.push.scope')}</span>
                <select
                  value={pushing.scope}
                  onChange={(event) => { void openPush(event.target.value) }}
                  className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
                >
                  {['both', 'files', 'database'].map((scope) => (
                    <option key={scope} value={scope}>
                      {t(`wordpress.push.scopes.${scope}`)}
                    </option>
                  ))}
                </select>
              </label>
              <ul className="mt-3 list-disc ps-5 text-sm" aria-label={t('wordpress.push.scope')}>
                {pushing.impact.warnings.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            </>
          }
          requiredPhrase={pushing.impact.production_domain}
          requiredPhraseLabel={t('wordpress.push.confirmLabel', { production: pushing.impact.production_domain })}
          confirmLabel={t('wordpress.push.confirm')}
          loading={push.isPending}
          {...(pushFailure === null ? {} : { error: pushFailure.message })}
          onCancel={() => {
            setPushing(null)
            push.reset()
          }}
          onConfirm={(confirmation) => {
            push.mutate(
              { siteId: site.id, scope: pushing.scope, confirmation },
              { onSuccess: () => { setPushing(null) } },
            )
          }}
        />
      )}
    </div>
  )
}

function Step({ done, label }: { done: boolean; label: string }) {
  return (
    <li className="flex items-center gap-2">
      <span aria-hidden className={done ? 'text-emerald-600 dark:text-emerald-400' : 'text-[var(--text-muted)]'}>
        {done ? '●' : '○'}
      </span>
      <span className={done ? '' : 'text-[var(--text-muted)]'}>{label}</span>
    </li>
  )
}
