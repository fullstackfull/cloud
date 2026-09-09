import { useState } from 'react'
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
import {
  ENVIRONMENTS,
  SAFETY_CLASSES,
  useAttachServerCredential,
  useClassifyServer,
  useClearForReimage,
  useCredentials,
  useDiscoverServer,
  useRegisterServer,
  useRevokeReimageClearance,
  useServerFacts,
  useServers,
  useTestServerConnection,
  type Environment,
  type SafetyClass,
  type Server,
} from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The machines, and the small number of things an operator may do to one.
 *
 * Two decisions live on this screen and are kept visibly apart: what KIND of
 * machine this is (the classification, raised one rung at a time) and whether
 * this one machine has been signed off for a wipe (the clearance, requiring
 * the name typed). The screen offers each control only where the API would
 * accept it, and says why otherwise — a control that is present and refused
 * teaches operators that the screen is wrong.
 *
 * A machine arrives do_not_touch and stays so until somebody decides. Nothing
 * on this screen infers that an idle-looking machine is disposable.
 */
export function ServersPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [environment, setEnvironment] = useState<Environment | ''>('')
  const [registering, setRegistering] = useState(false)
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const { data, isPending, error } = useServers(page, environment)
  const selected = data?.data.find((server) => server.id === selectedId) ?? null

  const columns: Array<Column<Server>> = [
    {
      key: 'name',
      header: t('admin.servers.name'),
      ltr: true,
      cell: (server) => (
        <div>
          <p className="technical font-medium text-[var(--text-primary)]">{server.name}</p>
          <p className="technical text-xs text-[var(--text-muted)]">
            {server.hardware.vendor ?? '—'} {server.hardware.model ?? ''}
          </p>
        </div>
      ),
    },
    {
      key: 'environment',
      header: t('admin.controlCenter.environment'),
      cell: (server) => t(`admin.controlCenter.environments.${server.environment}`),
    },
    {
      key: 'safety',
      header: t('admin.servers.classification'),
      cell: (server) => <SafetyBadge server={server} />,
    },
    {
      key: 'connection',
      header: t('admin.servers.connection'),
      cell: (server) => (
        <span className="flex flex-wrap items-center gap-2">
          <StatusBadge status={server.connection.state} />
          {server.connection.blocker === null ? null : (
            <Badge tone="warning">{t(`admin.controlCenter.blockers.${server.connection.blocker}`)}</Badge>
          )}
        </span>
      ),
    },
    {
      key: 'open',
      header: '',
      cell: (server) => (
        <Button size="sm" variant="ghost" onClick={() => { setSelectedId(server.id === selectedId ? null : server.id); }}>
          {server.id === selectedId ? t('common.close') : t('admin.servers.open')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('admin.servers.title')}
        description={t('admin.servers.subtitle')}
        actions={<Button onClick={() => { setRegistering((open) => !open); }}>{t('admin.servers.register')}</Button>}
      />

      {registering ? <RegisterServerForm onDone={() => { setRegistering(false); }} /> : null}

      <Card>
        <div className="mb-3 flex items-center gap-3">
          <label className="text-sm text-[var(--text-muted)]" htmlFor="server-environment">{t('admin.controlCenter.environment')}</label>
          <select
            id="server-environment"
            value={environment}
            onChange={(event) => { setEnvironment(event.target.value as Environment | ''); setPage(1); }}
            className="h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
          >
            <option value="">{t('admin.controlCenter.allEnvironments')}</option>
            {ENVIRONMENTS.map((value) => (
              <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>
            ))}
          </select>
        </div>

        {isPending ? (
          <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : error ? (
          <LoadFailure error={error} />
        ) : (
          <>
            <DataTable columns={columns} rows={data.data} rowKey={(server) => server.id} empty={t('admin.servers.empty')} caption={t('admin.servers.title')} />
            <Paginator page={page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />
          </>
        )}
      </Card>

      {selected === null ? null : <ServerDetail server={selected} />}
    </>
  )
}

function SafetyBadge({ server }: { server: Server }) {
  const { t } = useTranslation()
  const tone = { do_not_touch: 'danger', discovery_only: 'info', configuration_allowed: 'warning', reimage_allowed: 'danger' } as const

  return (
    <span className="flex flex-wrap items-center gap-2">
      <Badge tone={tone[server.safety.classification]}>{t(`admin.controlCenter.safety.${server.safety.classification}`)}</Badge>
      {server.safety.allow_reimage ? <Badge tone="danger">{t('admin.servers.clearedForReimage')}</Badge> : null}
    </span>
  )
}

/**
 * Everything about one machine, and every control the API would accept for it
 * right now.
 */
function ServerDetail({ server }: { server: Server }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describe = useApiErrorMessage()
  const [classifying, setClassifying] = useState(false)
  const [clearing, setClearing] = useState(false)
  const [revoking, setRevoking] = useState(false)

  const test = useTestServerConnection()
  const discover = useDiscoverServer()
  const revoke = useRevokeReimageClearance()
  const attach = useAttachServerCredential()
  const { data: credentials } = useCredentials(1, server.environment)
  const { data: facts } = useServerFacts(server.id)

  const canRead = server.safety.permits.read
  const testError = describe(test.error)
  const discoverError = describe(discover.error)
  const lastTest = test.data?.data

  return (
    <Card>
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="technical text-lg font-semibold">{server.name}</h2>
            <p className="text-sm text-[var(--text-muted)]">
              {t(`admin.controlCenter.environments.${server.environment}`)}
              {server.connection.bmc_address === null ? '' : ` · BMC ${server.connection.bmc_address}`}
              {server.connection.management_address === null ? '' : ` · ${server.connection.management_address}`}
            </p>
          </div>
          <span className="flex flex-wrap items-center gap-2">
            <StatusBadge status={server.state} />
            <SafetyBadge server={server} />
          </span>
        </div>

        {/* Safety: what kind of machine, and separately, whether this one is cleared. */}
        <section className="flex flex-col gap-2" aria-labelledby={`safety-${server.id}`}>
          <h3 id={`safety-${server.id}`} className="text-sm font-semibold">{t('admin.servers.safetyHeading')}</h3>
          {server.safety.reason === null ? (
            <p className="text-sm text-[var(--text-muted)]">{t('admin.servers.neverClassified')}</p>
          ) : (
            <p className="text-sm text-[var(--text-secondary)]">
              {server.safety.reason}
              {server.safety.changed_at === null ? '' : ` — ${formatDateTime(server.safety.changed_at, locale)}`}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" onClick={() => { setClassifying(true); }}>{t('admin.servers.classify')}</Button>
            {server.safety.classification === 'reimage_allowed' && !server.safety.allow_reimage ? (
              <Button size="sm" variant="danger" onClick={() => { setClearing(true); }}>{t('admin.servers.clearForReimage')}</Button>
            ) : null}
            {server.safety.allow_reimage ? (
              <Button size="sm" variant="ghost" loading={revoke.isPending} onClick={() => { setRevoking(true); }}>{t('admin.servers.revokeClearance')}</Button>
            ) : null}
          </div>
        </section>

        {/* Credential: which one, never what. */}
        <section className="flex flex-col gap-2" aria-labelledby={`credential-${server.id}`}>
          <h3 id={`credential-${server.id}`} className="text-sm font-semibold">{t('admin.servers.credentialHeading')}</h3>
          <div className="flex flex-wrap items-center gap-2">
            <select
              aria-label={t('admin.servers.credential')}
              value={server.connection.credential?.id ?? ''}
              disabled={attach.isPending}
              onChange={(event) => { attach.mutate({ id: server.id, credential_id: event.target.value === '' ? null : event.target.value }); }}
              className="h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
            >
              <option value="">{t('admin.servers.noCredential')}</option>
              {(credentials?.data ?? []).filter((c) => c.state !== 'revoked').map((credential) => (
                <option key={credential.id} value={credential.id}>{credential.name} — {t(`status.${credential.state}`)}</option>
              ))}
            </select>
            {server.connection.credential ? <StatusBadge status={server.connection.credential.state} /> : null}
          </div>
          {describe(attach.error) === null ? null : <Alert tone="error">{describe(attach.error)?.message}</Alert>}
        </section>

        {/* Reaching it: a test, then a look. Both reads, both gated the same way. */}
        <section className="flex flex-col gap-2" aria-labelledby={`reach-${server.id}`}>
          <h3 id={`reach-${server.id}`} className="text-sm font-semibold">{t('admin.servers.reachHeading')}</h3>
          {canRead ? null : <Alert tone="warning">{t('admin.servers.cannotReach')}</Alert>}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={!canRead} loading={test.isPending} onClick={() => { test.mutate({ id: server.id }); }}>
              {t('admin.servers.testConnection')}
            </Button>
            <Button size="sm" disabled={!canRead} loading={discover.isPending} onClick={() => { discover.mutate({ id: server.id }); }}>
              {t('admin.servers.discover')}
            </Button>
          </div>
          {testError === null ? null : <Alert tone="error">{testError.message}</Alert>}
          {discoverError === null ? null : <Alert tone="error">{discoverError.message}</Alert>}
          {lastTest === undefined ? null : (
            <div className="rounded-lg border border-[var(--border-subtle)] p-3 text-sm">
              <p className="flex items-center gap-2">
                <StatusBadge status={lastTest.result} />
                {lastTest.next_action === null ? null : <span className="text-[var(--text-muted)]">{t(lastTest.next_action)}</span>}
              </p>
              <ol className="technical mt-2 flex flex-col gap-1 text-xs">
                {lastTest.steps.map((step) => (
                  <li key={step.name}>
                    {step.outcome === 'passed' ? '✓' : '✗'} {step.name}{step.detail === undefined ? '' : ` — ${step.detail}`}
                  </li>
                ))}
              </ol>
            </div>
          )}
          {discover.data === undefined ? null : (
            <p className="text-sm text-[var(--text-secondary)]">
              {discover.data.data.usable
                ? t('admin.servers.discovered', { count: discover.data.data.facts })
                : t('admin.servers.discoveryFoundNothing')}
            </p>
          )}
        </section>

        {/* What is known. */}
        <section className="flex flex-col gap-2" aria-labelledby={`facts-${server.id}`}>
          <h3 id={`facts-${server.id}`} className="text-sm font-semibold">
            {t('admin.servers.factsHeading')}
            {server.last_discovery_at === null ? '' : ` · ${formatDateTime(server.last_discovery_at, locale)}`}
          </h3>
          {facts === undefined || facts.data.length === 0 ? (
            <p className="text-sm text-[var(--text-muted)]">{t('admin.servers.noFacts')}</p>
          ) : (
            <dl className="technical grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-xs" dir="ltr">
              {facts.data.map((fact) => (
                <div key={fact.key} className="contents">
                  <dt className="text-[var(--text-muted)]">{fact.key}</dt>
                  <dd>
                    {fact.value ?? '—'}{' '}
                    <span className="text-[var(--text-muted)]">({t(`admin.servers.sources.${fact.source}`)})</span>
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </section>
      </div>

      {classifying ? <ClassifyDialog server={server} onClose={() => { setClassifying(false); }} /> : null}
      {clearing ? <ClearDialog server={server} onClose={() => { setClearing(false); }} /> : null}

      <ConfirmDialog
        open={revoking}
        title={t('admin.servers.revokeClearanceTitle', { name: server.name })}
        body={<p>{t('admin.servers.revokeClearanceBody')}</p>}
        confirmLabel={t('admin.servers.revokeClearance')}
        loading={revoke.isPending}
        error={describe(revoke.error)?.message}
        onCancel={() => { setRevoking(false); }}
        onConfirm={() => { revoke.mutate({ id: server.id }, { onSuccess: () => { setRevoking(false); } }); }}
      />
    </Card>
  )
}

function ClassifyDialog({ server, onClose }: { server: Server; onClose: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const classify = useClassifyServer()
  const current = SAFETY_CLASSES.indexOf(server.safety.classification)
  // One rung up, any distance down: the same rule the API enforces, so the
  // select only offers what would be accepted.
  const offered = SAFETY_CLASSES.filter((_, index) => index === current + 1 || index < current)
  const [target, setTarget] = useState<SafetyClass | ''>('')
  const destructive = target === 'reimage_allowed'

  return (
    <ConfirmDialog
      open
      title={t('admin.servers.classifyTitle', { name: server.name })}
      body={
        <div className="flex flex-col gap-3">
          <p>{t('admin.servers.classifyBody')}</p>
          <div className="flex flex-col gap-1.5">
            <label htmlFor={`classify-${server.id}`} className="text-sm font-medium">{t('admin.servers.newClassification')}</label>
            <select
              id={`classify-${server.id}`}
              value={target}
              onChange={(event) => { setTarget(event.target.value as SafetyClass | ''); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              <option value="">—</option>
              {offered.map((value) => (
                <option key={value} value={value}>{t(`admin.controlCenter.safety.${value}`)}</option>
              ))}
            </select>
          </div>
          {destructive ? <Alert tone="warning">{t('admin.servers.destructiveRung')}</Alert> : null}
        </div>
      }
      evidenceLabel={t('admin.servers.reason')}
      evidenceHint={t('admin.servers.reasonHint')}
      {...(destructive ? { requiredPhrase: server.name } : {})}
      confirmLabel={t('admin.servers.classify')}
      ready={target !== ''}
      loading={classify.isPending}
      error={describe(classify.error)?.message}
      onCancel={onClose}
      onConfirm={(phrase, reason) => {
        if (target === '') return
        classify.mutate(
          { id: server.id, safety_class: target, reason, ...(destructive ? { confirm_name: phrase } : {}) },
          { onSuccess: onClose },
        )
      }}
    />
  )
}

function ClearDialog({ server, onClose }: { server: Server; onClose: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const clear = useClearForReimage()

  return (
    <ConfirmDialog
      open
      title={t('admin.servers.clearTitle', { name: server.name })}
      body={<p>{t('admin.servers.clearBody')}</p>}
      requiredPhrase={server.name}
      evidenceLabel={t('admin.servers.reason')}
      evidenceHint={t('admin.servers.clearReasonHint')}
      confirmLabel={t('admin.servers.clearForReimage')}
      loading={clear.isPending}
      error={describe(clear.error)?.message}
      onCancel={onClose}
      onConfirm={(phrase, reason) => { clear.mutate({ id: server.id, confirm_name: phrase, reason }, { onSuccess: onClose }); }}
    />
  )
}

function RegisterServerForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const register = useRegisterServer()
  const [name, setName] = useState('')
  const [environment, setEnvironment] = useState<Environment>('staging')
  const [management, setManagement] = useState('')
  const [bmc, setBmc] = useState('')
  const [vendor, setVendor] = useState('')
  const [model, setModel] = useState('')
  const [serial, setSerial] = useState('')
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.servers.registerTitle')}
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          register.mutate(
            {
              name: name.trim(),
              environment,
              ...(management.trim() === '' ? {} : { management_address: management.trim() }),
              ...(bmc.trim() === '' ? {} : { bmc_address: bmc.trim() }),
              ...(vendor.trim() === '' ? {} : { vendor: vendor.trim() }),
              ...(model.trim() === '' ? {} : { model: model.trim() }),
              ...(serial.trim() === '' ? {} : { serial: serial.trim() }),
            },
            { onSuccess: onDone },
          )
        }}
      >
        <h2 className="text-base font-semibold">{t('admin.servers.registerTitle')}</h2>
        <Alert tone="info">{t('admin.servers.registerNote')}</Alert>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('admin.servers.name')} value={name} onChange={(e) => { setName(e.target.value); }} error={fieldError('name')} dir="ltr" required />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="register-server-environment" className="text-sm font-medium">{t('admin.controlCenter.environment')}</label>
            <select id="register-server-environment" value={environment} onChange={(e) => { setEnvironment(e.target.value as Environment); }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm">
              {ENVIRONMENTS.map((value) => <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>)}
            </select>
          </div>
          <Field label={t('admin.servers.managementAddress')} value={management} onChange={(e) => { setManagement(e.target.value); }} error={fieldError('management_address')} dir="ltr" />
          <Field label={t('admin.servers.bmcAddress')} value={bmc} onChange={(e) => { setBmc(e.target.value); }} error={fieldError('bmc_address')} dir="ltr" />
          <Field label={t('admin.servers.vendor')} value={vendor} onChange={(e) => { setVendor(e.target.value); }} error={fieldError('vendor')} dir="ltr" />
          <Field label={t('admin.servers.model')} value={model} onChange={(e) => { setModel(e.target.value); }} error={fieldError('model')} dir="ltr" />
          <Field label={t('admin.servers.serial')} value={serial} onChange={(e) => { setSerial(e.target.value); }} error={fieldError('serial')} dir="ltr" />
        </div>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone} disabled={register.isPending}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending}>{t('admin.servers.submit')}</Button>
        </div>
      </form>
    </Card>
  )
}
