import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'
import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import {
  useApprovePlan,
  useAssignDesiredState,
  useClearDesiredState,
  useComputePlan,
  useCurrentPlan,
  useDesiredState,
  useRequestDeployment,
  useRevokeApproval,
  useServers,
  useSoftwareProfiles,
  type DeploymentPlan,
  type Server,
  type SoftwareProfile,
} from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The chain, one machine at a time: what it should be, what that would
 * take, who said yes, and the button that queues the run.
 *
 * Every control here is offered only where the API would accept it, and
 * the fingerprint is on the screen next to the approval so an operator can
 * see that what was approved is what would run.
 */
export function PlansPage() {
  const { t } = useTranslation()
  const [serverId, setServerId] = useState<string | null>(null)
  const servers = useServers(1)
  const selected = servers.data?.data.find((server) => server.id === serverId) ?? null

  return (
    <>
      <PageHeader title={t('admin.plans.title')} description={t('admin.plans.subtitle')} />

      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <label htmlFor="plan-machine" className="text-sm font-medium">{t('admin.plans.machine')}</label>
          {servers.isPending ? (
            <Loading />
          ) : servers.error ? (
            <LoadFailure error={servers.error} />
          ) : (
            <select
              id="plan-machine"
              value={serverId ?? ''}
              onChange={(event) => { setServerId(event.target.value === '' ? null : event.target.value); }}
              className="technical h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
              dir="ltr"
            >
              <option value="">{t('admin.plans.pickMachine')}</option>
              {servers.data.data.map((server) => (
                <option key={server.id} value={server.id}>{server.name}</option>
              ))}
            </select>
          )}
          {selected === null ? null : (
            <span className="flex items-center gap-2 text-xs">
              <Badge tone="info">{t(`admin.controlCenter.safety.${selected.safety.classification}`)}</Badge>
              <StatusBadge status={selected.state} />
            </span>
          )}
        </div>
      </Card>

      {selected === null ? null : <MachineChain server={selected} />}
    </>
  )
}

function MachineChain({ server }: { server: Server }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describe = useApiErrorMessage()
  const desired = useDesiredState(server.id)
  const plan = useCurrentPlan(server.id)
  const profiles = useSoftwareProfiles()
  const compute = useComputePlan()
  const request = useRequestDeployment()
  const revoke = useRevokeApproval()
  const clear = useClearDesiredState()
  const [approving, setApproving] = useState(false)
  const [revoking, setRevoking] = useState(false)
  const [clearing, setClearing] = useState(false)
  const [runKind, setRunKind] = useState<'apply' | 'verify' | null>(null)

  const current = plan.data?.data ?? null
  const approved = current?.approval !== null && current?.approval !== undefined
  const canRun = current !== null && current.is_applicable && approved
  const lastRun = request.data?.data

  return (
    <>
      <Card>
        <h2 className="mb-2 text-base font-semibold">{t('admin.plans.desiredHeading')}</h2>
        {desired.isPending || profiles.isPending ? (
          <Loading />
        ) : desired.error ? (
          <LoadFailure error={desired.error} />
        ) : profiles.error ? (
          <LoadFailure error={profiles.error} />
        ) : (
          <>
            {desired.data.data === null ? (
              <p className="mb-3 text-sm text-[var(--text-muted)]">{t('admin.plans.noDesiredState')}</p>
            ) : (
              <p className="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium">{desired.data.data.profile_name}</span>
                <span className="technical text-xs text-[var(--text-muted)]">{desired.data.data.profile}</span>
                {Object.entries(desired.data.data.overrides).map(([key, value]) => (
                  <span key={key} className="technical text-xs">{key}={value}</span>
                ))}
                <Button size="sm" variant="ghost" onClick={() => { setClearing(true); }}>{t('admin.plans.clear')}</Button>
              </p>
            )}
            <AssignForm server={server} profiles={profiles.data.data} current={desired.data.data?.profile ?? ''} currentOverrides={desired.data.data?.overrides ?? {}} />
          </>
        )}
      </Card>

      <Card>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-base font-semibold">{t('admin.plans.planHeading')}</h2>
          <Button size="sm" variant="secondary" loading={compute.isPending} disabled={desired.data?.data === null} onClick={() => { compute.mutate({ serverId: server.id }); }}>
            {t('admin.plans.compute')}
          </Button>
        </div>
        {describe(compute.error) === null ? null : <Alert tone="error">{describe(compute.error)?.message}</Alert>}
        {plan.isPending ? (
          <Loading />
        ) : plan.error ? (
          <LoadFailure error={plan.error} />
        ) : current === null ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.plans.noPlan')}</p>
        ) : (
          <PlanView plan={current} />
        )}
      </Card>

      {current === null ? null : (
        <Card>
          <h2 className="mb-2 text-base font-semibold">{t('admin.plans.approvalHeading')}</h2>
          {approved && current.approval !== null ? (
            <p className="flex flex-wrap items-center gap-2 text-sm">
              <Badge tone="success">{t('admin.plans.approved', { when: current.approval.approved_at === null ? '' : formatDateTime(current.approval.approved_at, locale) })}</Badge>
              <span className="technical text-xs text-[var(--text-muted)]">{current.approval.approved_fingerprint.slice(0, 12)}</span>
              <Button size="sm" variant="ghost" onClick={() => { setRevoking(true); }}>{t('admin.plans.revokeApproval')}</Button>
            </p>
          ) : (
            <p className="flex flex-wrap items-center gap-2 text-sm">
              <span className="text-[var(--text-muted)]">{t('admin.plans.notApproved')}</span>
              <Button size="sm" disabled={!current.is_applicable} onClick={() => { setApproving(true); }}>{t('admin.plans.approve')}</Button>
            </p>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-2">
            <Button size="sm" variant="danger" disabled={!canRun} loading={request.isPending && runKind === 'apply'} onClick={() => { setRunKind('apply'); request.mutate({ serverId: server.id, kind: 'apply' }); }}>
              {t('admin.plans.run')}
            </Button>
            <Button size="sm" variant="secondary" loading={request.isPending && runKind === 'verify'} onClick={() => { setRunKind('verify'); request.mutate({ serverId: server.id, kind: 'verify' }); }}>
              {t('admin.plans.verify')}
            </Button>
            <span className="text-xs text-[var(--text-muted)]">{t('admin.plans.runNote')}</span>
          </div>
          {describe(request.error) === null ? null : <Alert tone="error">{describe(request.error)?.message}</Alert>}
          {lastRun === undefined ? null : (
            <p className="mt-2 flex items-center gap-2 text-sm">
              <span>{t(`admin.deployments.kinds.${lastRun.kind}`)}</span>
              <StatusBadge status={lastRun.state} />
              {lastRun.failure_detail === null ? null : <span className="text-xs text-[var(--text-muted)]">{lastRun.failure_detail}</span>}
            </p>
          )}
        </Card>
      )}

      {approving && current !== null ? <ApproveDialog server={server} plan={current} onClose={() => { setApproving(false); }} /> : null}

      <ConfirmDialog
        open={revoking}
        title={t('admin.plans.revokeTitle', { name: server.name })}
        body={<p>{t('admin.plans.revokeBody')}</p>}
        evidenceLabel={t('admin.plans.approveReason')}
        confirmLabel={t('admin.plans.revokeApproval')}
        loading={revoke.isPending}
        error={describe(revoke.error)?.message}
        onCancel={() => { setRevoking(false); revoke.reset(); }}
        onConfirm={(_phrase, reason) => { if (current !== null) revoke.mutate({ planId: current.id, reason }, { onSuccess: () => { setRevoking(false); } }); }}
      />

      <ConfirmDialog
        open={clearing}
        title={t('admin.plans.clearTitle', { name: server.name })}
        body={<p>{t('admin.plans.clearBody')}</p>}
        evidenceLabel={t('admin.plans.approveReason')}
        confirmLabel={t('admin.plans.clear')}
        loading={clear.isPending}
        error={describe(clear.error)?.message}
        onCancel={() => { setClearing(false); clear.reset(); }}
        onConfirm={(_phrase, reason) => { clear.mutate({ serverId: server.id, reason }, { onSuccess: () => { setClearing(false); } }); }}
      />
    </>
  )
}

function PlanView({ plan }: { plan: DeploymentPlan }) {
  const { t } = useTranslation()
  const riskTone = { none: 'neutral', low: 'info', moderate: 'warning', high: 'warning', destructive: 'danger' } as const

  return (
    <div className="flex flex-col gap-3 text-sm">
      <p className="flex flex-wrap items-center gap-2">
        <Badge tone={plan.risk in riskTone ? riskTone[plan.risk as keyof typeof riskTone] : 'neutral'}>{t('admin.plans.risk')}: {t(`admin.plans.risks.${plan.risk}`)}</Badge>
        <span className="text-xs text-[var(--text-muted)]">{t('admin.plans.requires')} {t(`admin.controlCenter.safety.${plan.required_safety_class}`)}</span>
        {plan.requires_reboot ? <Badge tone="warning">{t('admin.plans.reboot')}</Badge> : null}
        {plan.is_destructive ? <Badge tone="danger">{t('admin.plans.destructive')}</Badge> : null}
        <span className="technical text-xs text-[var(--text-muted)]" title={plan.fingerprint}>{t('admin.plans.fingerprint')} {plan.fingerprint.slice(0, 12)}</span>
      </p>

      {plan.blockers.length === 0 ? null : (
        <Alert tone="warning">
          <ul className="flex flex-col gap-1" aria-label={t('admin.plans.blockers')}>
            {plan.blockers.map((blocker) => (
              <li key={blocker.code}>{t(`admin.plans.blockerCodes.${blocker.code}`)} <span className="text-xs">{blocker.detail}</span></li>
            ))}
          </ul>
        </Alert>
      )}

      {plan.changes.length === 0 ? (
        <p className="text-[var(--text-muted)]">{t('admin.plans.nothingToDo')}</p>
      ) : (
        <ul className="flex flex-col gap-1" aria-label={t('admin.plans.changes', { count: plan.changes.length })}>
          {plan.changes.map((change) => (
            <li key={change.component} className="flex flex-wrap items-center gap-2">
              <span>{t(`admin.plans.actions.${change.action}`)}</span>
              <span className="technical font-medium">{change.component}</span>
              <Badge tone={change.risk in riskTone ? riskTone[change.risk as keyof typeof riskTone] : 'neutral'}>{t(`admin.plans.risks.${change.risk}`)}</Badge>
              {Object.entries(change.configuration).map(([key, value]) => (
                <span key={key} className="technical text-xs text-[var(--text-muted)]">{key}={value}</span>
              ))}
            </li>
          ))}
        </ul>
      )}
      {plan.unchanged.length === 0 ? null : (
        <p className="text-xs text-[var(--text-muted)]">{t('admin.plans.unchanged', { count: plan.unchanged.length })}: <span className="technical">{plan.unchanged.map((row) => row.component).join(', ')}</span></p>
      )}
    </div>
  )
}

function AssignForm({ server, profiles, current, currentOverrides }: { server: Server; profiles: SoftwareProfile[]; current: string; currentOverrides: Record<string, string> }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const assign = useAssignDesiredState()
  const [profileKey, setProfileKey] = useState(current)
  const [overrides, setOverrides] = useState<Record<string, string>>(currentOverrides)
  const profile = profiles.find((candidate) => candidate.key === profileKey)
  const accepted = profile === undefined ? [] : profile.components.flatMap((component) => component.accepts.map((key) => `${component.key}.${key}`))
  const failure = describe(assign.error)

  return (
    <form
      aria-label={t('admin.plans.assign')}
      className="flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        if (profile === undefined) return
        const kept: Record<string, string> = {}
        for (const key of accepted) {
          const value = (overrides[key] ?? '').trim()
          if (value !== '') kept[key] = value
        }
        assign.mutate({ serverId: server.id, profile: profile.key, overrides: kept })
      }}
    >
      <Alert tone="info">{t('admin.plans.assignNote')}</Alert>
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`profile-${server.id}`} className="text-sm font-medium">{t('admin.plans.profile')}</label>
          <select
            id={`profile-${server.id}`}
            value={profileKey}
            onChange={(event) => { setProfileKey(event.target.value); }}
            className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            required
          >
            <option value="">—</option>
            {profiles.map((candidate) => <option key={candidate.key} value={candidate.key}>{candidate.name}</option>)}
          </select>
        </div>
        {accepted.map((key) => (
          <Field
            key={key}
            label={key}
            value={overrides[key] ?? ''}
            onChange={(event) => { setOverrides((prev) => ({ ...prev, [key]: event.target.value })); }}
            dir="ltr"
          />
        ))}
        <Button type="submit" loading={assign.isPending} disabled={profile === undefined}>{t('admin.plans.assign')}</Button>
      </div>
      {accepted.length === 0 ? null : <p className="text-xs text-[var(--text-muted)]">{t('admin.plans.overridesHint')}</p>}
      {failure === null ? null : <Alert tone="error">{failure.message}</Alert>}
    </form>
  )
}

function ApproveDialog({ server, plan, onClose }: { server: Server; plan: DeploymentPlan; onClose: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const approve = useApprovePlan()

  return (
    <ConfirmDialog
      open
      title={t('admin.plans.approveTitle', { name: server.name })}
      body={
        <div className="flex flex-col gap-2">
          <p>{t('admin.plans.approveBody')}</p>
          <p className="technical text-xs" dir="ltr">{plan.fingerprint}</p>
          {plan.is_destructive ? <Alert tone="warning">{t('admin.plans.approveDestructive')}</Alert> : null}
        </div>
      }
      {...(plan.is_destructive ? { requiredPhrase: server.name } : {})}
      evidenceLabel={t('admin.plans.approveReason')}
      evidenceHint={t('admin.plans.approveReasonHint')}
      confirmLabel={t('admin.plans.approve')}
      loading={approve.isPending}
      error={describe(approve.error)?.message}
      onCancel={onClose}
      onConfirm={(phrase, reason) => {
        approve.mutate({ planId: plan.id, reason, ...(plan.is_destructive ? { confirm_name: phrase } : {}) }, { onSuccess: onClose })
      }}
    />
  )
}
