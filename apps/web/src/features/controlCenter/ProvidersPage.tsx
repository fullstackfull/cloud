import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'
import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { CapabilityBadge } from '@/features/controlCenter/CapabilityBadge'
import {
  ENVIRONMENTS,
  useAttachProviderCredential,
  useAttachProviderLicence,
  useCatalogue,
  useCredentials,
  useDisableProvider,
  useEnableProvider,
  useLicences,
  useProviders,
  useRegisterProvider,
  useServers,
  useTestProviderConnection,
  type CatalogueEntry,
  type Environment,
  type Provider,
} from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The accounts Lynomia holds with other people, and the two decisions about
 * each one.
 *
 * Registering says a provider is meant to exist. Enabling says work may be
 * routed to it. Between them sit a credential, sometimes a licence, one
 * successful test and a discovery — and the readiness column names, in
 * dependency order, the first of those that is still missing. The screen
 * offers "Enable" only when the API would accept it and otherwise shows what
 * to do instead, in the operator's language.
 */
export function ProvidersPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [environment, setEnvironment] = useState<Environment | ''>('')
  const [registering, setRegistering] = useState(false)
  // A deep link from the readiness screen names the provider to open.
  const [params] = useSearchParams()
  const [selectedId, setSelectedId] = useState<string | null>(params.get('open'))

  const { data, isPending, error } = useProviders(page, { environment })
  const selected = data?.data.find((provider) => provider.id === selectedId) ?? null

  const columns: Array<Column<Provider>> = [
    {
      key: 'name',
      header: t('admin.providers.name'),
      ltr: true,
      cell: (provider) => (
        <div>
          <p className="technical font-medium text-[var(--text-primary)]">{provider.name}</p>
          <p className="text-xs text-[var(--text-muted)]">
            {t(`admin.providers.categories.${provider.category}`)} · <span className="technical">{provider.driver}</span>
          </p>
        </div>
      ),
    },
    {
      key: 'environment',
      header: t('admin.controlCenter.environment'),
      cell: (provider) => t(`admin.controlCenter.environments.${provider.environment}`),
    },
    {
      key: 'state',
      header: t('admin.providers.state'),
      cell: (provider) => (
        <span className="flex flex-wrap items-center gap-2">
          <StatusBadge status={provider.state} />
          {provider.is_serving ? <Badge tone="success">{t('admin.providers.serving')}</Badge> : null}
        </span>
      ),
    },
    {
      key: 'readiness',
      header: t('admin.providers.readiness'),
      cell: (provider) => (
        <div className="flex flex-col gap-1">
          <StatusBadge status={provider.readiness.state} />
          {provider.readiness.blocker === null ? null : (
            <span className="text-xs text-[var(--text-muted)]">
              {t(`admin.controlCenter.blockers.${provider.readiness.blocker}`)}
              {provider.readiness.next_action === null ? '' : ` — ${t(provider.readiness.next_action)}`}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'open',
      header: '',
      cell: (provider) => (
        <Button size="sm" variant="ghost" onClick={() => { setSelectedId(provider.id === selectedId ? null : provider.id); }}>
          {provider.id === selectedId ? t('common.close') : t('admin.providers.open')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('admin.providers.title')}
        description={t('admin.providers.subtitle')}
        actions={<Button onClick={() => { setRegistering((open) => !open); }}>{t('admin.providers.register')}</Button>}
      />

      {registering ? <RegisterProviderForm onDone={() => { setRegistering(false); }} /> : null}

      <Card>
        <div className="mb-3 flex items-center gap-3">
          <label className="text-sm text-[var(--text-muted)]" htmlFor="provider-environment">{t('admin.controlCenter.environment')}</label>
          <select
            id="provider-environment"
            value={environment}
            onChange={(event) => { setEnvironment(event.target.value as Environment | ''); setPage(1); }}
            className="h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
          >
            <option value="">{t('admin.controlCenter.allEnvironments')}</option>
            {ENVIRONMENTS.map((value) => <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>)}
          </select>
        </div>

        {isPending ? (
          <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : error ? (
          <LoadFailure error={error} />
        ) : (
          <>
            <DataTable columns={columns} rows={data.data} rowKey={(provider) => provider.id} empty={t('admin.providers.empty')} caption={t('admin.providers.title')} />
            <Paginator page={page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />
          </>
        )}
      </Card>

      {selected === null ? null : <ProviderDetail provider={selected} />}
    </>
  )
}

function ProviderDetail({ provider }: { provider: Provider }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describe = useApiErrorMessage()
  const [disabling, setDisabling] = useState(false)

  const test = useTestProviderConnection()
  const enable = useEnableProvider()
  const disable = useDisableProvider()
  const attachCredential = useAttachProviderCredential()
  const attachLicence = useAttachProviderLicence()
  const { data: credentials } = useCredentials(1, provider.environment)
  const { data: licences } = useLicences(1, provider.environment)

  const ready = provider.readiness.state === 'ready_for_production'
  const lastTest = test.data?.data

  return (
    <Card>
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="technical text-lg font-semibold">{provider.name}</h2>
            <p className="text-sm text-[var(--text-muted)]">
              {t(`admin.providers.categories.${provider.category}`)} · <span className="technical">{provider.driver}</span>
              {provider.endpoint === null ? '' : ` · ${provider.endpoint}`}
              {provider.server ? ` · ${provider.server.server_name}` : ''}
            </p>
          </div>
          <span className="flex items-center gap-2">
            <StatusBadge status={provider.state} />
            <StatusBadge status={provider.readiness.state} />
          </span>
        </div>

        {provider.readiness.blocker === null ? null : (
          <Alert tone="info">
            {t(`admin.controlCenter.blockers.${provider.readiness.blocker}`)}
            {provider.readiness.next_action === null ? '' : ` — ${t(provider.readiness.next_action)}`}
          </Alert>
        )}

        <section className="grid gap-4 sm:grid-cols-2" aria-label={t('admin.providers.dependencies')}>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor={`credential-${provider.id}`}>{t('admin.providers.credential')}</label>
            <select
              id={`credential-${provider.id}`}
              value={provider.credential?.id ?? ''}
              disabled={attachCredential.isPending}
              onChange={(event) => { attachCredential.mutate({ id: provider.id, credential_id: event.target.value === '' ? null : event.target.value }); }}
              className="h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
            >
              <option value="">{t('admin.providers.noCredential')}</option>
              {(credentials?.data ?? []).filter((c) => c.state !== 'revoked').map((credential) => (
                <option key={credential.id} value={credential.id}>{credential.name} — {t(`status.${credential.state}`)}</option>
              ))}
            </select>
            {describe(attachCredential.error) === null ? null : <Alert tone="error">{describe(attachCredential.error)?.message}</Alert>}
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor={`licence-${provider.id}`}>{t('admin.providers.licence')}</label>
            <select
              id={`licence-${provider.id}`}
              value={provider.licence?.id ?? ''}
              disabled={attachLicence.isPending}
              onChange={(event) => { attachLicence.mutate({ id: provider.id, licence_id: event.target.value === '' ? null : event.target.value }); }}
              className="h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
            >
              <option value="">{t('admin.providers.noLicence')}</option>
              {(licences?.data ?? []).filter((l) => l.state !== 'invalid').map((licence) => (
                <option key={licence.id} value={licence.id}>{licence.product} — {t(`status.${licence.state}`)}</option>
              ))}
            </select>
            {describe(attachLicence.error) === null ? null : <Alert tone="error">{describe(attachLicence.error)?.message}</Alert>}
          </div>
        </section>

        <section className="flex flex-col gap-2" aria-labelledby={`test-${provider.id}`}>
          <h3 id={`test-${provider.id}`} className="text-sm font-semibold">{t('admin.providers.testHeading')}</h3>
          {provider.can_test ? null : <Alert tone="warning">{t('admin.providers.cannotTest', { driver: provider.driver })}</Alert>}
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" disabled={!provider.can_test} loading={test.isPending} onClick={() => { test.mutate({ id: provider.id }); }}>
              {t('admin.providers.testAndDiscover')}
            </Button>
            <StatusBadge status={provider.connection.state} />
            {provider.connection.last_tested_at === null ? null : (
              <span className="text-xs text-[var(--text-muted)]">{formatDateTime(provider.connection.last_tested_at, locale)}</span>
            )}
          </div>
          {describe(test.error) === null ? null : <Alert tone="error">{describe(test.error)?.message}</Alert>}
          {lastTest === undefined ? null : (
            <ol className="technical flex flex-col gap-1 rounded-lg border border-[var(--border-subtle)] p-3 text-xs">
              {lastTest.steps.map((step) => (
                <li key={step.name}>{step.outcome === 'passed' ? '✓' : '✗'} {step.name}{step.detail === undefined ? '' : ` — ${step.detail}`}</li>
              ))}
            </ol>
          )}
        </section>

        <section className="flex flex-col gap-2" aria-labelledby={`capabilities-${provider.id}`}>
          <h3 id={`capabilities-${provider.id}`} className="text-sm font-semibold">{t('admin.providers.capabilitiesHeading')}</h3>
          {provider.capabilities === undefined || provider.capabilities.length === 0 ? (
            <p className="text-sm text-[var(--text-muted)]">{t('admin.providers.noCapabilities')}</p>
          ) : (
            <ul className="flex flex-wrap gap-2" aria-label={t('admin.providers.capabilitiesHeading')}>
              {provider.capabilities.map((capability) => (
                <li key={capability.capability}>
                  <CapabilityBadge capability={capability.capability} state={capability.capability_state} />
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="flex flex-col gap-2" aria-labelledby={`serve-${provider.id}`}>
          <h3 id={`serve-${provider.id}`} className="text-sm font-semibold">{t('admin.providers.serveHeading')}</h3>
          <div className="flex flex-wrap gap-2">
            {provider.state === 'enabled' ? (
              <Button size="sm" variant="danger" onClick={() => { setDisabling(true); }}>{t('admin.providers.disable')}</Button>
            ) : (
              <Button size="sm" disabled={!ready} loading={enable.isPending} onClick={() => { enable.mutate({ id: provider.id }); }}>
                {t('admin.providers.enable')}
              </Button>
            )}
          </div>
          {ready || provider.state === 'enabled' ? null : <p className="text-xs text-[var(--text-muted)]">{t('admin.providers.notReadyToEnable')}</p>}
          {describe(enable.error) === null ? null : <Alert tone="error">{describe(enable.error)?.message}</Alert>}
          {provider.state === 'disabled' && provider.disabled_reason !== null ? (
            <p className="text-sm text-[var(--text-secondary)]">{t('admin.providers.disabledBecause', { reason: provider.disabled_reason })}</p>
          ) : null}
        </section>
      </div>

      <ConfirmDialog
        open={disabling}
        title={t('admin.providers.disableTitle', { name: provider.name })}
        body={<p>{t('admin.providers.disableBody')}</p>}
        evidenceLabel={t('admin.providers.disableReason')}
        confirmLabel={t('admin.providers.disable')}
        loading={disable.isPending}
        error={describe(disable.error)?.message}
        onCancel={() => { setDisabling(false); disable.reset(); }}
        onConfirm={(_phrase, reason) => { disable.mutate({ id: provider.id, reason }, { onSuccess: () => { setDisabling(false); } }); }}
      />
    </Card>
  )
}

function RegisterProviderForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const register = useRegisterProvider()
  const { data: catalogue } = useCatalogue()
  const { data: servers } = useServers(1)

  const [driver, setDriver] = useState('')
  const [name, setName] = useState('')
  const [environment, setEnvironment] = useState<Environment>('staging')
  const [endpoint, setEndpoint] = useState('')
  const [serverId, setServerId] = useState('')

  const entry: CatalogueEntry | undefined = catalogue?.data.find((candidate) => candidate.driver === driver)
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.providers.registerTitle')}
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          if (entry === undefined) return
          register.mutate(
            {
              name: name.trim(),
              driver: entry.driver,
              category: entry.category,
              environment,
              ...(endpoint.trim() === '' ? {} : { endpoint: endpoint.trim() }),
              ...(serverId === '' ? {} : { managed_server_id: serverId }),
            },
            { onSuccess: onDone },
          )
        }}
      >
        <h2 className="text-base font-semibold">{t('admin.providers.registerTitle')}</h2>
        <Alert tone="info">{t('admin.providers.registerNote')}</Alert>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="register-provider-driver" className="text-sm font-medium">{t('admin.providers.driver')}</label>
            <select
              id="register-provider-driver"
              value={driver}
              onChange={(event) => { setDriver(event.target.value); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
              required
            >
              <option value="">—</option>
              {(catalogue?.data ?? []).filter((candidate) => candidate.available_here).map((candidate) => (
                <option key={candidate.driver} value={candidate.driver}>
                  {candidate.driver} — {t(`admin.providers.categories.${candidate.category}`)}
                </option>
              ))}
            </select>
            {entry === undefined ? null : (
              <p className="text-xs text-[var(--text-muted)]">
                {entry.summary}{' '}
                {entry.testable ? null : <strong>{t('admin.providers.notTestableYet')}</strong>}
              </p>
            )}
          </div>

          <Field label={t('admin.providers.name')} value={name} onChange={(e) => { setName(e.target.value); }} error={fieldError('name')} dir="ltr" required />

          <div className="flex flex-col gap-1.5">
            <label htmlFor="register-provider-environment" className="text-sm font-medium">{t('admin.controlCenter.environment')}</label>
            <select id="register-provider-environment" value={environment} onChange={(e) => { setEnvironment(e.target.value as Environment); }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm">
              {ENVIRONMENTS.map((value) => <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>)}
            </select>
          </div>

          {entry?.needs_endpoint ? (
            <Field label={t('admin.providers.endpoint')} value={endpoint} onChange={(e) => { setEndpoint(e.target.value); }} error={fieldError('endpoint')} dir="ltr" required />
          ) : null}

          {entry?.needs_server ? (
            <div className="flex flex-col gap-1.5">
              <label htmlFor="register-provider-server" className="text-sm font-medium">{t('admin.providers.runsOn')}</label>
              <select id="register-provider-server" value={serverId} onChange={(e) => { setServerId(e.target.value); }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm" required>
                <option value="">—</option>
                {(servers?.data ?? []).filter((server) => server.environment === environment).map((server) => (
                  <option key={server.id} value={server.id}>{server.name}</option>
                ))}
              </select>
            </div>
          ) : null}
        </div>

        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone} disabled={register.isPending}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending} disabled={entry === undefined}>{t('admin.providers.submit')}</Button>
        </div>
      </form>
    </Card>
  )
}
