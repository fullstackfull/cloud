import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { SelectField } from '@/components/SelectField'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useCloneWordPressSite,
  useCreateWordPressStaging,
  usePushWordPressToProduction,
  useWordPressPushImpact,
  useWordPressSiteOperations,
} from '@/lib/queries'
import type { WordPressPushImpact, WordPressSite } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Copies of a site and the push back.
 *
 * Moved out of the sites list in Wave 3 and onto the site's own page, where
 * the rest of what is true about that site is. Nothing about how it behaves
 * changed: the impact preview is still fetched before the dialogue opens, the
 * production domain is still typed back, the buttons still appear only where
 * the panel's toolkit can do the thing, and an operation the platform did not
 * hear the end of is still shown as needing review rather than retried.
 *
 * The buttons are there or not as the site's panel says, with the reason
 * when not: a toolkit that cannot copy is a button that honestly does not
 * exist. The push is the dangerous one and is arranged like every
 * destructive act here — what it overwrites is fetched and shown in words
 * before the production domain is asked to be typed back, and the words
 * include what this platform holds no backup of.
 */
export function SiteCopies({ site }: { site: WordPressSite }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data: operations, error: operationsError } = useWordPressSiteOperations(site.id)
  const staging = useCreateWordPressStaging()
  const clone = useCloneWordPressSite()
  const impact = useWordPressPushImpact()
  const push = usePushWordPressToProduction()

  const [cloning, setCloning] = useState(false)
  const [cloneDomain, setCloneDomain] = useState('')
  const [pushing, setPushing] = useState<{ scope: string; impact: WordPressPushImpact } | null>(null)

  const failure = describeError(staging.error ?? clone.error ?? impact.error)
  const pushFailure = describeError(push.error)

  // A copy already in progress must not read as none in progress.
  const rows = operations?.data ?? []
  const canDoAnything = site.copies.staging || site.copies.clone || site.copies.push_to_production

  async function openPush(scope: string) {
    const answer = await impact.mutateAsync({ siteId: site.id, scope }).catch(() => null)

    if (answer !== null) {
      setPushing({ scope, impact: answer.data })
    }
  }

  return (
    <div>
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

      {/*
        Above the list, not inside it: the case that matters is the empty one,
        where "no copies running" and "we could not check" look identical and
        the remedy for the first is to start another copy.
      */}
      <LoadFailure error={operationsError} />

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
              <SelectField
                label={t('wordpress.push.scope')}
                value={pushing.scope}
                onChange={(event) => { void openPush(event.target.value) }}
                options={['both', 'files', 'database'].map((scope) => ({
                  value: scope,
                  label: t(`wordpress.push.scopes.${scope}`),
                }))}
              />
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
