import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import {
  BILLING_PERIODS,
  PRODUCT_KINDS,
  type BillingPeriod,
  type CataloguePlan,
  type CatalogueProduct,
  type ProductKind,
  useCataloguePlans,
  useCatalogueProducts,
  useHostingPackages,
  useMapHostingPackage,
  useRecordCataloguePlan,
  useRecordCatalogueProduct,
  useSetPlanPrice,
  useWithdrawCataloguePlan,
  useWithdrawCatalogueProduct,
  useWithdrawHostingPackage,
  useWithdrawPlanPrice,
} from '@/lib/controlCenterQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * What this deployment sells, and for how much.
 *
 * Before this screen and the endpoints under it, a production deployment could
 * model everything it sold and sell none of it: the only thing that wrote the
 * catalogue was a seeder which refuses to run in production, so the routes
 * forward were raw SQL or a code change.
 *
 * Two things the screen is careful about. It shows the rows a customer never
 * sees — withdrawn, unlisted, unpriced — because "why is this not being
 * offered" is what an operator comes here to find out. And it never implies a
 * thing is on sale: whether a product may actually be sold is the readiness
 * engine's answer, shown on its own page, and a plan with a price is only ever
 * configuration.
 */
export function OperatorCataloguePage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const products = useCatalogueProducts()
  const plans = useCataloguePlans()
  const packages = useHostingPackages()
  const [addingProduct, setAddingProduct] = useState(false)
  const [addingPlan, setAddingPlan] = useState(false)
  const [addingPackage, setAddingPackage] = useState(false)
  const [pricing, setPricing] = useState<string | null>(null)
  const withdrawProduct = useWithdrawCatalogueProduct()
  const withdrawPlan = useWithdrawCataloguePlan()
  const withdrawPackage = useWithdrawHostingPackage()
  const withdrawPrice = useWithdrawPlanPrice()

  const named = (names: Record<string, string>): string =>
    names[locale] ?? names.en ?? Object.values(names)[0] ?? ''

  return (
    <>
      <PageHeader
        title={t('admin.catalogue.title')}
        description={t('admin.catalogue.subtitle')}
        actions={
          <span className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => { setAddingProduct((open) => !open); }}>
              {t('admin.catalogue.recordProduct')}
            </Button>
            <Button variant="secondary" onClick={() => { setAddingPlan((open) => !open); }}>
              {t('admin.catalogue.recordPlan')}
            </Button>
            <Button onClick={() => { setAddingPackage((open) => !open); }}>
              {t('admin.catalogue.mapPackage')}
            </Button>
          </span>
        }
      />

      {addingProduct ? <ProductForm onDone={() => { setAddingProduct(false); }} /> : null}
      {addingPlan ? <PlanForm products={products.data?.data ?? []} onDone={() => { setAddingPlan(false); }} /> : null}
      {addingPackage ? <PackageForm plans={plans.data?.data ?? []} onDone={() => { setAddingPackage(false); }} /> : null}

      <Card>
        <h2 className="mb-3 text-sm font-medium">{t('admin.catalogue.products')}</h2>
        {products.isPending ? (
          <Loading />
        ) : products.error ? (
          <LoadFailure error={products.error} />
        ) : products.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.catalogue.noProducts')}</p>
        ) : (
          <ul className="flex flex-col gap-3" aria-label={t('admin.catalogue.products')}>
            {products.data.data.map((product) => (
              <li key={product.id} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={product.slug}>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p>
                    <span className="font-medium">{named(product.name)}</span>{' '}
                    <span className="technical text-xs text-[var(--text-muted)]">{product.slug} · {product.kind}</span>
                  </p>
                  <span className="flex items-center gap-2">
                    <Badge tone={product.is_active ? 'success' : 'neutral'}>
                      {t(product.is_active ? 'admin.catalogue.listed' : 'admin.catalogue.withdrawn')}
                    </Badge>
                    {product.is_public ? null : <Badge tone="neutral">{t('admin.catalogue.unlisted')}</Badge>}
                    <span className="text-xs text-[var(--text-muted)]">
                      {t('admin.catalogue.planCount', { count: product.plans_count ?? 0 })}
                    </span>
                    {product.is_active ? (
                      <Button
                        variant="ghost"
                        onClick={() => { withdrawProduct.mutate({ id: product.id }); }}
                      >
                        {t('admin.catalogue.withdraw')}
                      </Button>
                    ) : null}
                  </span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <h2 className="mb-3 text-sm font-medium">{t('admin.catalogue.plans')}</h2>
        {plans.isPending ? (
          <Loading />
        ) : plans.error ? (
          <LoadFailure error={plans.error} />
        ) : plans.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.catalogue.noPlans')}</p>
        ) : (
          <>
            {(plans.data.meta.unpriced ?? 0) > 0 ? (
              <Alert tone="warning">{t('admin.catalogue.unpriced', { count: plans.data.meta.unpriced })}</Alert>
            ) : null}
            <ul className="flex flex-col gap-3" aria-label={t('admin.catalogue.plans')}>
              {plans.data.data.map((plan) => (
                <li key={plan.id} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={plan.slug}>
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p>
                      <span className="font-medium">{named(plan.name)}</span>{' '}
                      <span className="technical text-xs text-[var(--text-muted)]">{plan.slug}</span>
                    </p>
                    <span className="flex items-center gap-2">
                      <Badge tone={plan.is_active ? 'success' : 'neutral'}>
                        {t(plan.is_active ? 'admin.catalogue.listed' : 'admin.catalogue.withdrawn')}
                      </Badge>
                      <Button variant="ghost" onClick={() => { setPricing(pricing === plan.id ? null : plan.id); }}>
                        {t('admin.catalogue.setPrice')}
                      </Button>
                      {plan.is_active ? (
                        <Button variant="ghost" onClick={() => { withdrawPlan.mutate({ id: plan.id }); }}>
                          {t('admin.catalogue.withdraw')}
                        </Button>
                      ) : null}
                    </span>
                  </div>

                  <p className="mt-1 text-xs text-[var(--text-muted)]">
                    {(plan.priced_in ?? []).length === 0
                      ? t('admin.catalogue.noPrice')
                      : t('admin.catalogue.pricedIn', { currencies: (plan.priced_in ?? []).join(', ') })}
                  </p>

                  {(plan.prices ?? []).length > 0 ? (
                    <ul className="mt-2 flex flex-col gap-1" aria-label={t('admin.catalogue.pricesFor', { plan: plan.slug })}>
                      {(plan.prices ?? []).map((price) => (
                        <li key={price.id} className="technical flex flex-wrap items-center gap-2 text-xs" dir="ltr" aria-label={`${price.currency} ${price.billing_period}`}>
                          <span className="font-medium">{price.currency}</span>
                          <span>{price.billing_period}</span>
                          <span>{price.recurring_amount_minor}</span>
                          {price.is_active ? (
                            <Button
                              variant="ghost"
                              onClick={() => { withdrawPrice.mutate({ planId: plan.id, priceId: price.id }); }}
                            >
                              {t('admin.catalogue.withdraw')}
                            </Button>
                          ) : (
                            <Badge tone="neutral">{t('admin.catalogue.withdrawn')}</Badge>
                          )}
                        </li>
                      ))}
                    </ul>
                  ) : null}

                  {pricing === plan.id ? <PriceForm plan={plan} onDone={() => { setPricing(null); }} /> : null}
                </li>
              ))}
            </ul>
          </>
        )}
      </Card>

      <Card>
        <h2 className="mb-3 text-sm font-medium">{t('admin.catalogue.packages')}</h2>
        <p className="mb-3 text-xs text-[var(--text-muted)]">{t('admin.catalogue.packagesNote')}</p>
        {packages.isPending ? (
          <Loading />
        ) : packages.error ? (
          <LoadFailure error={packages.error} />
        ) : packages.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.catalogue.noPackages')}</p>
        ) : (
          <ul className="flex flex-col gap-2" aria-label={t('admin.catalogue.packages')}>
            {packages.data.data.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[var(--border-subtle)] p-3" aria-label={item.slug}>
                <p>
                  <span className="technical font-medium">{item.slug}</span>{' '}
                  <span className="technical text-xs text-[var(--text-muted)]">→ {item.panel_package_name}</span>
                </p>
                <span className="flex items-center gap-2">
                  <Badge tone={item.mapped ? 'success' : 'warning'}>
                    {t(item.mapped ? 'admin.catalogue.mapped' : 'admin.catalogue.unmapped')}
                  </Badge>
                  {item.is_active ? (
                    <Button variant="ghost" onClick={() => { withdrawPackage.mutate({ id: item.id }); }}>
                      {t('admin.catalogue.withdraw')}
                    </Button>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}

function ProductForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const record = useRecordCatalogueProduct()
  const [kind, setKind] = useState<ProductKind>('vps')
  const [slug, setSlug] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [nameAr, setNameAr] = useState('')
  const failure = describe(record.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.catalogue.recordProduct')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          record.mutate({
            kind,
            slug: slug.trim(),
            name: { en: nameEn.trim(), ar: nameAr.trim() },
            is_active: true,
            is_public: true,
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="product-kind" className="text-sm font-medium">{t('admin.catalogue.kind')}</label>
            <select
              id="product-kind"
              value={kind}
              onChange={(e) => { setKind(e.target.value as ProductKind); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
              required
            >
              {PRODUCT_KINDS.map((option) => <option key={option} value={option}>{t(`admin.catalogue.kinds.${option}`)}</option>)}
            </select>
          </div>
          <Field label={t('admin.catalogue.slug')} value={slug} onChange={(e) => { setSlug(e.target.value); }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.catalogue.nameEn')} value={nameEn} onChange={(e) => { setNameEn(e.target.value); }} error={fieldError('name.en')} dir="ltr" required />
          <Field label={t('admin.catalogue.nameAr')} value={nameAr} onChange={(e) => { setNameAr(e.target.value); }} error={fieldError('name.ar')} dir="rtl" required />
        </div>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={record.isPending}>{t('admin.catalogue.recordProduct')}</Button>
        </div>
      </form>
    </Card>
  )
}

function PlanForm({ products, onDone }: { products: CatalogueProduct[]; onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const record = useRecordCataloguePlan()
  const [productId, setProductId] = useState('')
  const [slug, setSlug] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [vcpu, setVcpu] = useState('')
  const [memory, setMemory] = useState('')
  const [disk, setDisk] = useState('')
  const [profile, setProfile] = useState('')
  const [quota, setQuota] = useState('')
  const failure = describe(record.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]
  const kind = products.find((product) => product.id === productId)?.kind

  /*
   * The fields follow the kind, because what a plan must say depends on it: a
   * VPS build reads vCPU, memory and disk with defaults behind them, so a plan
   * that omits them would quietly sell the smallest machine. A generic JSON
   * box would let somebody do exactly that.
   */
  const resources = (): Record<string, unknown> => {
    if (kind === 'vps') {
      return { vcpu: Number(vcpu), memory_mib: Number(memory), disk_gib: Number(disk) }
    }

    if (kind === 'dedicated') {
      return { hardware_profile: profile.trim() }
    }

    return quota.trim() === '' ? {} : { disk_quota_mib: Number(quota) }
  }

  return (
    <Card>
      <form
        aria-label={t('admin.catalogue.recordPlan')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          record.mutate({
            product_id: productId,
            slug: slug.trim(),
            name: { en: nameEn.trim(), ar: nameAr.trim() },
            resources: resources(),
            is_active: true,
            is_public: true,
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="plan-product" className="text-sm font-medium">{t('admin.catalogue.product')}</label>
            <select
              id="plan-product"
              value={productId}
              onChange={(e) => { setProductId(e.target.value); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
              required
            >
              <option value="">—</option>
              {products.map((product) => <option key={product.id} value={product.id}>{product.slug}</option>)}
            </select>
          </div>
          <Field label={t('admin.catalogue.slug')} value={slug} onChange={(e) => { setSlug(e.target.value); }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.catalogue.nameEn')} value={nameEn} onChange={(e) => { setNameEn(e.target.value); }} error={fieldError('name.en')} dir="ltr" required />
          <Field label={t('admin.catalogue.nameAr')} value={nameAr} onChange={(e) => { setNameAr(e.target.value); }} error={fieldError('name.ar')} dir="rtl" required />

          {kind === 'vps' ? (
            <>
              <Field label={t('admin.catalogue.vcpu')} type="number" min={1} value={vcpu} onChange={(e) => { setVcpu(e.target.value); }} dir="ltr" required />
              <Field label={t('admin.catalogue.memory')} type="number" min={1} value={memory} onChange={(e) => { setMemory(e.target.value); }} dir="ltr" required />
              <Field label={t('admin.catalogue.disk')} type="number" min={1} value={disk} onChange={(e) => { setDisk(e.target.value); }} dir="ltr" required />
            </>
          ) : null}

          {kind === 'dedicated' ? (
            <Field label={t('admin.catalogue.hardwareProfile')} value={profile} onChange={(e) => { setProfile(e.target.value); }} dir="ltr" required />
          ) : null}

          {kind === 'shared_hosting' ? (
            <Field label={t('admin.catalogue.diskQuota')} type="number" min={1} value={quota} onChange={(e) => { setQuota(e.target.value); }} dir="ltr" />
          ) : null}
        </div>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={record.isPending}>{t('admin.catalogue.recordPlan')}</Button>
        </div>
      </form>
    </Card>
  )
}

function PriceForm({ plan, onDone }: { plan: CataloguePlan; onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const set = useSetPlanPrice()
  const [currency, setCurrency] = useState('KWD')
  const [period, setPeriod] = useState<BillingPeriod>('monthly')
  const [minor, setMinor] = useState('')
  const failure = describe(set.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <form
      // Its own name: the list of existing prices above carries
      // `pricesFor`, and two controls with one accessible name is a screen
      // reader announcing the same thing twice.
      aria-label={t('admin.catalogue.setPriceFor', { plan: plan.slug })}
      className="mt-3 flex flex-col gap-3 rounded-lg border border-[var(--border-subtle)] p-3"
      onSubmit={(event) => {
        event.preventDefault()
        set.mutate({
          planId: plan.id,
          currency: currency.trim().toUpperCase(),
          billing_period: period,
          recurring_amount_minor: Number(minor),
          setup_amount_minor: 0,
          is_active: true,
        }, { onSuccess: onDone })
      }}
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('admin.catalogue.currency')} value={currency} onChange={(e) => { setCurrency(e.target.value); }} error={fieldError('currency')} dir="ltr" required />
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`period-${plan.id}`} className="text-sm font-medium">{t('admin.catalogue.period')}</label>
          <select
            id={`period-${plan.id}`}
            value={period}
            onChange={(e) => { setPeriod(e.target.value as BillingPeriod); }}
            className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            required
          >
            {BILLING_PERIODS.map((option) => <option key={option} value={option}>{option}</option>)}
          </select>
        </div>
        {/*
          * Minor units, asked for as minor units. A decimal box would need a
          * conversion, and a conversion in a browser is a rounding decision
          * taken in the one place nobody can test the money in.
          */}
        <Field
          label={t('admin.catalogue.amountMinor')}
          hint={t('admin.catalogue.amountMinorHint')}
          type="number"
          min={0}
          step={1}
          value={minor}
          onChange={(e) => { setMinor(e.target.value); }}
          error={fieldError('recurring_amount_minor')}
          dir="ltr"
          required
        />
      </div>
      {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
        <Button type="submit" loading={set.isPending}>{t('admin.catalogue.setPrice')}</Button>
      </div>
    </form>
  )
}

function PackageForm({ plans, onDone }: { plans: CataloguePlan[]; onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const map = useMapHostingPackage()
  const [slug, setSlug] = useState('')
  const [panelName, setPanelName] = useState('')
  const [planId, setPlanId] = useState('')
  const failure = describe(map.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.catalogue.mapPackage')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          map.mutate({
            slug: slug.trim(),
            panel_package_name: panelName.trim(),
            ...(planId === '' ? {} : { plan_id: planId }),
            is_active: true,
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('admin.catalogue.slug')} value={slug} onChange={(e) => { setSlug(e.target.value); }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.catalogue.panelPackage')} hint={t('admin.catalogue.panelPackageHint')} value={panelName} onChange={(e) => { setPanelName(e.target.value); }} error={fieldError('panel_package_name')} dir="ltr" required />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="package-plan" className="text-sm font-medium">{t('admin.catalogue.plan')}</label>
            <select
              id="package-plan"
              value={planId}
              onChange={(e) => { setPlanId(e.target.value); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              <option value="">—</option>
              {plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.slug}</option>)}
            </select>
          </div>
        </div>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={map.isPending}>{t('admin.catalogue.mapPackage')}</Button>
        </div>
      </form>
    </Card>
  )
}
