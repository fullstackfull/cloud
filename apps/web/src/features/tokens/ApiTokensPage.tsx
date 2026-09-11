import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { SelectField } from '@/components/SelectField'
import { TextareaField } from '@/components/TextareaField'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useApiTokens, useCreateApiToken, useRevokeApiToken } from '@/lib/queries'
import type { ApiToken } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * An ISO instant `n` days from now, for the expiry the customer picked.
 *
 * Computed in the browser and validated on the server, which refuses anything
 * already past. Sent as an instant rather than a number of days so that the
 * value in the request is the value stored — a server that did its own
 * arithmetic would disagree with the screen by however long the request took.
 */
function inDays(days: number): string {
  const at = new Date()
  at.setDate(at.getDate() + days)

  return at.toISOString()
}

export function ApiTokensPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data, isPending, error: readError } = useApiTokens()
  const create = useCreateApiToken()
  const revoke = useRevokeApiToken()

  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [issued, setIssued] = useState<string | null>(null)

  /*
   * The three restrictions the platform enforces, and nothing else.
   *
   * There is deliberately no scope or ability control. The token model has an
   * `abilities` column, it always holds `["*"]`, and no endpoint checks it —
   * so a scope picker here would be a security control that looks like one
   * and restricts nothing, which is worse than the absence of one. Reported
   * rather than invented.
   *
   * Expiry defaults to ninety days rather than to never. A credential that
   * outlives the integration it was minted for is the one that is still valid
   * when a laptop is sold, and "no expiry" stays available for the customer
   * who genuinely wants it — it is just no longer the thing you get by not
   * choosing.
   */
  const [expiresIn, setExpiresIn] = useState('90')
  const [ipRanges, setIpRanges] = useState('')
  const [rateLimit, setRateLimit] = useState('')

  // The token about to be revoked. Named in the dialogue, because "revoke
  // this?" over a row of similar names is how the wrong integration dies.
  const [revoking, setRevoking] = useState<ApiToken | null>(null)

  const displayed = describeError(create.error ?? revoke.error)

  const columns: Array<Column<ApiToken>> = [
    { key: 'name', header: t('tokens.name'), cell: (token) => token.name },
    { key: 'status', header: t('tokens.status'), cell: (token) => <StatusBadge status={token.status} /> },
    {
      key: 'restrictions',
      header: t('tokens.restrictions'),
      /*
       * What this credential can and cannot do, summarised in one column
       * rather than three. The list used to show none of it, so a customer
       * auditing their own tokens could not tell the one pinned to a build
       * server's address from the one that works from anywhere.
       *
       * Only the restrictions the platform enforces are summarised here. The
       * abilities column exists on the row, always holds the wildcard, and is
       * checked by nothing — printing "full access" from it would be reading
       * a field as a promise.
       */
      cell: (token) => {
        const parts: string[] = []

        if (token.allowed_ip_ranges !== null && token.allowed_ip_ranges.length > 0) {
          parts.push(t('tokens.fromAddresses', { count: token.allowed_ip_ranges.length }))
        }

        if (token.rate_limit_per_minute !== null) {
          parts.push(t('tokens.perMinute', { count: token.rate_limit_per_minute }))
        }

        return parts.length === 0 ? (
          <span className="text-[var(--text-muted)]">{t('tokens.unrestricted')}</span>
        ) : (
          <span>{parts.join(' · ')}</span>
        )
      },
    },
    {
      key: 'expires',
      header: t('tokens.expires'),
      ltr: true,
      cell: (token) =>
        token.expires_at === null ? (
          <span className="text-[var(--text-muted)]">{t('tokens.expiryNever')}</span>
        ) : (
          formatDateTime(token.expires_at, locale)
        ),
    },
    {
      key: 'used',
      header: t('tokens.lastUsed'),
      ltr: true,
      cell: (token) =>
        token.last_used_at === null ? (
          <span className="text-[var(--text-muted)]">{t('tokens.neverUsed')}</span>
        ) : (
          <span>
            {formatDateTime(token.last_used_at, locale)}
            {token.last_used_ip !== null ? (
              <span className="technical ms-2 text-xs text-[var(--text-muted)]">
                {token.last_used_ip}
              </span>
            ) : null}
          </span>
        ),
    },
    {
      key: 'actions',
      header: '',
      cell: (token) =>
        token.revoked_at === null ? (
          <Button
            size="sm"
            variant="ghost"
            loading={revoke.isPending && revoke.variables === token.id}
            onClick={() => { setRevoking(token); }}
          >
            {t('tokens.revoke')}
          </Button>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.apiKeys')} description={t('tokens.subtitle')} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      {issued !== null ? (
        <div className="mb-4">
          <Alert tone="warning" title={t('tokens.copyItNow')}>
            <p className="mb-2">{t('tokens.shownOnce')}</p>
            <code dir="ltr" className="technical block rounded-md bg-[var(--surface-sunken)] p-2 text-xs select-all">
              {issued}
            </code>
          </Alert>
        </div>
      ) : null}

      <div className="flex flex-col gap-4">
        <Card title={t('tokens.createTitle')} description={t('tokens.createSubtitle')}>
          <form
            className="flex max-w-md flex-col gap-3"
            noValidate
            onSubmit={(event) => {
              event.preventDefault()
              const ranges = ipRanges
                .split(/[\n,]/)
                .map((entry) => entry.trim())
                .filter((entry) => entry !== '')

              const limit = Number.parseInt(rateLimit, 10)

              create.mutate(
                {
                  name,
                  current_password: password,
                  // Each omitted rather than sent empty, because the server
                  // reads absence as "no restriction" and an empty array as
                  // an allow-list that matches nothing.
                  ...(expiresIn === 'never' ? {} : { expires_at: inDays(Number(expiresIn)) }),
                  ...(ranges.length === 0 ? {} : { allowed_ip_ranges: ranges }),
                  ...(Number.isFinite(limit) && limit > 0 ? { rate_limit_per_minute: limit } : {}),
                },
                {
                  onSuccess: (result) => {
                    setIssued(result.plain_text_token)
                    setName('')
                    setPassword('')
                    setExpiresIn('90')
                    setIpRanges('')
                    setRateLimit('')
                  },
                },
              )
            }}
          >
            <Field
              label={t('tokens.name')}
              value={name}
              onChange={(event) => { setName(event.target.value); }}
              required
              error={displayed?.fields?.['name']?.[0]}
            />

            {/*
              The account password, because a token outlives the session that
              created it. A stolen session must not be able to mint a
              credential that survives the customer changing their password.
            */}
            <Field
              label={t('security.currentPassword')}
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => { setPassword(event.target.value); }}
              required
              error={displayed?.fields?.['current_password']?.[0]}
            />

            <SelectField
              label={t('tokens.expires')}
              hint={t('tokens.expiresHint')}
              value={expiresIn}
              onChange={(event) => { setExpiresIn(event.target.value); }}
              error={displayed?.fields?.['expires_at']?.[0]}
              options={[
                { value: '30', label: t('tokens.expiryDays', { count: 30 }) },
                { value: '90', label: t('tokens.expiryDays', { count: 90 }) },
                { value: '365', label: t('tokens.expiryDays', { count: 365 }) },
                { value: 'never', label: t('tokens.expiryNever') },
              ]}
            />

            <TextareaField
              label={t('tokens.ipRanges')}
              hint={t('tokens.ipRangesHint')}
              // A CIDR block is a technical value and stays left to right on
              // an Arabic page; reversed, it is not a network.
              dir="ltr"
              className="technical"
              rows={3}
              placeholder="198.51.100.0/24"
              value={ipRanges}
              onChange={(event) => { setIpRanges(event.target.value); }}
              error={displayed?.fields?.['allowed_ip_ranges']?.[0] ?? displayed?.fields?.['allowed_ip_ranges.0']?.[0]}
            />

            <Field
              label={t('tokens.rateLimit')}
              hint={t('tokens.rateLimitHint')}
              type="number"
              inputMode="numeric"
              min={1}
              dir="ltr"
              value={rateLimit}
              onChange={(event) => { setRateLimit(event.target.value); }}
              error={displayed?.fields?.['rate_limit_per_minute']?.[0]}
            />

            <div>
              <Button type="submit" loading={create.isPending}>
                {t('tokens.create')}
              </Button>
            </div>
          </form>
        </Card>

        <Card title={t('tokens.existing')}>
          {/*
            * Three states, kept apart: loading, failed, loaded. A read that
            * failed used to fall through to the table with no rows and read
            * as "No tokens" — which is the opposite of "you may not see the
            * tokens" and of "the server is down".
            */}
          <LoadFailure error={readError} />
          {isPending ? (
            <Loading />
          ) : readError !== null ? null : (
            <DataTable
              caption={t('nav.apiKeys')}
              columns={columns}
              rows={data.data}
              rowKey={(token) => token.id}
              empty={t('tokens.empty')}
            />
          )}
        </Card>
      </div>

      <ConfirmDialog
        open={revoking !== null}
        title={t('tokens.revokeDialog.title', { name: revoking?.name ?? '' })}
        body={<p>{t('tokens.revokeDialog.body')}</p>}
        confirmLabel={t('tokens.revokeDialog.confirmLabel')}
        loading={revoke.isPending}
        onConfirm={() => {
          if (revoking === null) return

          revoke.mutate(revoking.id, { onSettled: () => { setRevoking(null); } })
        }}
        onCancel={() => { setRevoking(null); }}
      />
    </>
  )
}
