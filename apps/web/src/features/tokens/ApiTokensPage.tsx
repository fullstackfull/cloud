import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useApiTokens, useCreateApiToken, useRevokeApiToken } from '@/lib/queries'
import type { ApiToken } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

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

  const displayed = describeError(readError ?? create.error ?? revoke.error)

  const columns: Array<Column<ApiToken>> = [
    { key: 'name', header: t('tokens.name'), cell: (token) => token.name },
    { key: 'status', header: t('tokens.status'), cell: (token) => <StatusBadge status={token.status} /> },
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
            onClick={() => { revoke.mutate(token.id); }}
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
              create.mutate(
                { name, current_password: password },
                {
                  onSuccess: (result) => {
                    setIssued(result.plain_text_token)
                    setName('')
                    setPassword('')
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

            <div>
              <Button type="submit" loading={create.isPending}>
                {t('tokens.create')}
              </Button>
            </div>
          </form>
        </Card>

        <Card title={t('tokens.existing')}>
          {isPending ? (
            <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
          ) : (
            <DataTable
              caption={t('nav.apiKeys')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(token) => token.id}
              empty={t('tokens.empty')}
            />
          )}
        </Card>
      </div>
    </>
  )
}
