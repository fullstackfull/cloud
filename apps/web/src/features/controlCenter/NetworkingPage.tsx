import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import {
  useComputeClusters,
  useDatacenters,
  useIpPools,
  useNetworks,
  useRegisterComputeCluster,
  useRegisterIpPool,
  useRegisterNetwork,
  useRegisterSubnet,
  useSubnets,
} from '@/lib/controlCenterQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The half of the estate a machine is actually built on: the cluster it runs
 * in, the segment it sits on, and the addresses it answers at.
 *
 * None of this could be created before. Address pools could be listed and not
 * added to; networks, subnets and clusters had no endpoint at all; and the
 * image catalogue, which does have one, began by finding a cluster nothing
 * could create. A new deployment therefore had a screen that could describe
 * an estate in detail and no way to be given one.
 *
 * Nothing on this page contacts anything. A cluster written here has never
 * been dialled — the "not contacted" note beside one is the literal state of
 * its `last_synced_at`, and it is there because configured and verified are
 * different claims and an operator screen is where they are most easily
 * confused.
 */
export function NetworkingPage() {
  const { t } = useTranslation()
  const clusters = useComputeClusters()
  const networks = useNetworks()
  const pools = useIpPools()
  const [adding, setAdding] = useState<'cluster' | 'network' | 'pool' | null>(null)
  const [openPool, setOpenPool] = useState<string | null>(null)

  const toggle = (form: 'cluster' | 'network' | 'pool') => { setAdding((open) => (open === form ? null : form)) }

  return (
    <>
      <PageHeader
        title={t('admin.networking.title')}
        description={t('admin.networking.subtitle')}
        actions={
          <span className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => { toggle('cluster') }}>{t('admin.networking.registerCluster')}</Button>
            <Button variant="secondary" onClick={() => { toggle('network') }}>{t('admin.networking.registerNetwork')}</Button>
            <Button onClick={() => { toggle('pool') }}>{t('admin.networking.registerPool')}</Button>
          </span>
        }
      />

      {adding === 'cluster' ? <ClusterForm onDone={() => { setAdding(null) }} /> : null}
      {adding === 'network' ? <NetworkForm onDone={() => { setAdding(null) }} /> : null}
      {adding === 'pool' ? <PoolForm onDone={() => { setAdding(null) }} /> : null}

      <Card title={t('admin.networking.clusters')}>
        {clusters.isPending ? (
          <Loading />
        ) : clusters.error ? (
          <LoadFailure error={clusters.error} />
        ) : clusters.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.networking.noClusters')}</p>
        ) : (
          <ul className="flex flex-col gap-2" aria-label={t('admin.networking.clusters')}>
            {clusters.data.data.map((cluster) => (
              <li key={cluster.id} className="flex flex-wrap items-baseline gap-2 rounded-lg border border-[var(--border-subtle)] p-3" aria-label={cluster.name}>
                <span className="font-medium">{cluster.name}</span>
                <span className="technical text-xs text-[var(--text-muted)]">{cluster.slug} · {cluster.driver}</span>
                <span className="text-xs text-[var(--text-muted)]">{t('admin.networking.clusterCounts', { nodes: cluster.nodes, templates: cluster.templates })}</span>
                <span className="text-xs text-[var(--text-muted)]">
                  {cluster.last_synced_at === null ? t('admin.networking.notContacted') : t('admin.networking.lastSynced')}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title={t('admin.networking.networks')}>
        {networks.isPending ? (
          <Loading />
        ) : networks.error ? (
          <LoadFailure error={networks.error} />
        ) : networks.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.networking.noNetworks')}</p>
        ) : (
          <ul className="flex flex-col gap-2" aria-label={t('admin.networking.networks')}>
            {networks.data.data.map((network) => (
              <li key={network.id} className="flex flex-wrap items-baseline gap-2 rounded-lg border border-[var(--border-subtle)] p-3" aria-label={network.slug}>
                <span className="font-medium">{network.name ?? network.slug}</span>
                <span className="technical text-xs text-[var(--text-muted)]">{network.slug} · {network.purpose}</span>
                {network.vlan_id === null ? null : <span className="technical text-xs text-[var(--text-muted)]">VLAN {network.vlan_id}</span>}
                {/*
                  * Said out loud rather than left to be inferred from the
                  * purpose: a management segment never carries a customer
                  * workload, and the platform decides that rather than the
                  * form.
                  */}
                <span className="text-xs text-[var(--text-muted)]">
                  {network.is_customer_facing ? t('admin.networking.customerFacing') : t('admin.networking.internalOnly')}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title={t('admin.networking.pools')}>
        {pools.isPending ? (
          <Loading />
        ) : pools.error ? (
          <LoadFailure error={pools.error} />
        ) : pools.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.networking.noPools')}</p>
        ) : (
          <ul className="flex flex-col gap-2" aria-label={t('admin.networking.pools')}>
            {pools.data.data.map((pool) => (
              <li key={pool.id} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={pool.slug}>
                <div className="flex flex-wrap items-baseline gap-2">
                  <span className="font-medium">{pool.name ?? pool.slug}</span>
                  <span className="technical text-xs text-[var(--text-muted)]">{pool.slug} · IPv{pool.ip_version}</span>
                  {/*
                    * The distinction that matters on this screen. A management
                    * pool reaches the hypervisor and BMC control planes; it is
                    * never customer capacity, and the scope cannot be edited
                    * afterwards, so the label is the whole story.
                    */}
                  <span className="text-xs text-[var(--text-muted)]">
                    {pool.scope === 'management' ? t('admin.networking.managementPool') : t('admin.networking.customerPool')}
                  </span>
                  <Button variant="ghost" onClick={() => { setOpenPool((open) => (open === pool.id ? null : pool.id)) }}>
                    {t('admin.networking.addSubnet')}
                  </Button>
                </div>
                {openPool === pool.id ? <SubnetForm poolId={pool.id} onDone={() => { setOpenPool(null) }} /> : null}
                <SubnetList poolId={pool.id} />
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}

function SubnetList({ poolId }: { poolId: string }) {
  const { t } = useTranslation()
  const subnets = useSubnets(poolId)

  if (subnets.isPending || subnets.error) return null

  if (subnets.data.data.length === 0) {
    return <p className="mt-2 text-xs text-[var(--text-muted)]">{t('admin.networking.noSubnets')}</p>
  }

  return (
    <ul className="mt-2 flex flex-col gap-1" aria-label={t('admin.networking.subnetsIn', { pool: poolId })}>
      {subnets.data.data.map((subnet) => (
        <li key={subnet.id} className="technical text-xs text-[var(--text-muted)]" aria-label={subnet.cidr}>
          {subnet.cidr}{subnet.gateway === null ? '' : ` · ${subnet.gateway}`}
        </li>
      ))}
    </ul>
  )
}

function DatacenterChooser({ id, value, onChange }: { id: string; value: string; onChange: (value: string) => void }) {
  const { t } = useTranslation()
  const datacenters = useDatacenters()

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium">{t('admin.sites.datacenter')}</label>
      <select
        id={id}
        value={value}
        onChange={(e) => { onChange(e.target.value) }}
        className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
        required
      >
        <option value="">—</option>
        {(datacenters.data?.data ?? []).map((dc) => <option key={dc.id} value={dc.id}>{dc.name}</option>)}
      </select>
    </div>
  )
}

function ClusterForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const register = useRegisterComputeCluster()
  const [datacenterId, setDatacenterId] = useState('')
  const [slug, setSlug] = useState('')
  const [name, setName] = useState('')
  const [endpoint, setEndpoint] = useState('')
  const [credential, setCredential] = useState('')
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.networking.registerCluster')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          register.mutate({
            datacenter_id: datacenterId,
            slug: slug.trim(),
            name: name.trim(),
            driver: 'proxmox',
            ...(endpoint.trim() === '' ? {} : { api_endpoint: endpoint.trim() }),
            ...(credential.trim() === '' ? {} : { credentials_reference: credential.trim() }),
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <DatacenterChooser id="cluster-dc" value={datacenterId} onChange={setDatacenterId} />
          <Field label={t('admin.sites.slug')} hint={t('admin.sites.slugHint')} value={slug} onChange={(e) => { setSlug(e.target.value) }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.sites.name')} value={name} onChange={(e) => { setName(e.target.value) }} error={fieldError('name')} required />
          <Field label={t('admin.networking.endpoint')} hint={t('admin.networking.endpointHint')} value={endpoint} onChange={(e) => { setEndpoint(e.target.value) }} error={fieldError('api_endpoint')} dir="ltr" />
          {/* A name in the credential centre, never a token: there is no field
              on this form, and no column on the row, that takes a secret. */}
          <Field label={t('admin.networking.credentialReference')} hint={t('admin.networking.credentialHint')} value={credential} onChange={(e) => { setCredential(e.target.value) }} error={fieldError('credentials_reference')} dir="ltr" />
        </div>
        <Alert tone="info">{t('admin.networking.configuredNotVerified')}</Alert>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending}>{t('admin.networking.registerCluster')}</Button>
        </div>
      </form>
    </Card>
  )
}

function NetworkForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const register = useRegisterNetwork()
  const [datacenterId, setDatacenterId] = useState('')
  const [slug, setSlug] = useState('')
  const [name, setName] = useState('')
  const [purpose, setPurpose] = useState('public')
  const [vlan, setVlan] = useState('')
  const [bridge, setBridge] = useState('')
  const [customerFacing, setCustomerFacing] = useState(false)
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  // Said on the form rather than discovered after saving: the platform decides
  // this for control-plane segments whatever the box says.
  const canFaceCustomers = purpose !== 'management' && purpose !== 'cluster_interconnect'

  return (
    <Card>
      <form
        aria-label={t('admin.networking.registerNetwork')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          register.mutate({
            datacenter_id: datacenterId,
            slug: slug.trim(),
            name: name.trim(),
            purpose,
            ...(vlan.trim() === '' ? {} : { vlan_id: Number(vlan) }),
            ...(bridge.trim() === '' ? {} : { bridge: bridge.trim() }),
            is_customer_facing: canFaceCustomers && customerFacing,
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <DatacenterChooser id="net-dc" value={datacenterId} onChange={setDatacenterId} />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="net-purpose" className="text-sm font-medium">{t('admin.networking.purpose')}</label>
            <select id="net-purpose" value={purpose} onChange={(e) => { setPurpose(e.target.value) }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm">
              {['public', 'private', 'storage', 'management', 'cluster_interconnect'].map((option) => (
                <option key={option} value={option}>{t(`admin.networking.purposes.${option}`)}</option>
              ))}
            </select>
          </div>
          <Field label={t('admin.sites.slug')} hint={t('admin.sites.slugHint')} value={slug} onChange={(e) => { setSlug(e.target.value) }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.sites.name')} value={name} onChange={(e) => { setName(e.target.value) }} error={fieldError('name')} required />
          <Field label={t('admin.networking.vlan')} type="number" min={1} max={4094} value={vlan} onChange={(e) => { setVlan(e.target.value) }} error={fieldError('vlan_id')} dir="ltr" />
          <Field label={t('admin.networking.bridge')} value={bridge} onChange={(e) => { setBridge(e.target.value) }} error={fieldError('bridge')} dir="ltr" />
        </div>
        {canFaceCustomers ? (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={customerFacing} onChange={(e) => { setCustomerFacing(e.target.checked) }} />
            {t('admin.networking.customerFacingLabel')}
          </label>
        ) : (
          <Alert tone="info">{t('admin.networking.neverCustomerFacing')}</Alert>
        )}
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending}>{t('admin.networking.registerNetwork')}</Button>
        </div>
      </form>
    </Card>
  )
}

function PoolForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const register = useRegisterIpPool()
  const [datacenterId, setDatacenterId] = useState('')
  const [slug, setSlug] = useState('')
  const [name, setName] = useState('')
  const [scope, setScope] = useState('public')
  const [version, setVersion] = useState('4')
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.networking.registerPool')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          register.mutate({
            datacenter_id: datacenterId,
            slug: slug.trim(),
            name: name.trim(),
            ip_version: Number(version),
            scope,
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <DatacenterChooser id="pool-dc" value={datacenterId} onChange={setDatacenterId} />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="pool-scope" className="text-sm font-medium">{t('admin.networking.scope')}</label>
            <select id="pool-scope" value={scope} onChange={(e) => { setScope(e.target.value) }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm">
              {['public', 'private', 'management'].map((option) => (
                <option key={option} value={option}>{t(`admin.networking.scopes.${option}`)}</option>
              ))}
            </select>
          </div>
          <Field label={t('admin.sites.slug')} hint={t('admin.sites.slugHint')} value={slug} onChange={(e) => { setSlug(e.target.value) }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.sites.name')} value={name} onChange={(e) => { setName(e.target.value) }} error={fieldError('name')} required />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="pool-version" className="text-sm font-medium">{t('admin.networking.ipVersion')}</label>
            <select id="pool-version" value={version} onChange={(e) => { setVersion(e.target.value) }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm">
              <option value="4">IPv4</option>
              <option value="6">IPv6</option>
            </select>
          </div>
        </div>
        {/*
          * Both facts are on the form because both are permanent. A management
          * pool is never customer capacity, and neither the scope nor the
          * address family can be changed afterwards — a pool of another kind
          * is a new pool.
          */}
        <Alert tone={scope === 'management' ? 'warning' : 'info'}>
          {scope === 'management' ? t('admin.networking.managementWarning') : t('admin.networking.scopeIsPermanent')}
        </Alert>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending}>{t('admin.networking.registerPool')}</Button>
        </div>
      </form>
    </Card>
  )
}

function SubnetForm({ poolId, onDone }: { poolId: string; onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const register = useRegisterSubnet(poolId)
  const [cidr, setCidr] = useState('')
  const [gateway, setGateway] = useState('')
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <form
      aria-label={t('admin.networking.addSubnet')}
      className="mt-3 flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        register.mutate({
          cidr: cidr.trim(),
          ...(gateway.trim() === '' ? {} : { gateway: gateway.trim() }),
        }, { onSuccess: onDone })
      }}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('admin.networking.cidr')} hint={t('admin.networking.cidrHint')} value={cidr} onChange={(e) => { setCidr(e.target.value) }} error={fieldError('cidr')} dir="ltr" required />
        <Field label={t('admin.networking.gateway')} value={gateway} onChange={(e) => { setGateway(e.target.value) }} error={fieldError('gateway')} dir="ltr" />
      </div>
      {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
        <Button type="submit" loading={register.isPending}>{t('admin.networking.addSubnet')}</Button>
      </div>
    </form>
  )
}
