import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useHostingUsage } from '@/lib/queries'
import type { HostingUsageMeasure } from '@/lib/types'

/**
 * Disk and bandwidth against quota, with the date the platform last heard.
 *
 * Nothing here is live and the card says so. The endpoint reads the platform's
 * own record of the last panel sync — asking the node per request would let any
 * customer put load on the shared machine their neighbours' sites run on — so
 * every figure is stamped, and a reading older than the API's own staleness
 * window is labelled rather than presented as current.
 *
 * A figure the platform has not been told is "not measured", never zero. Panels
 * answer with the numbers absent more often than anybody expects, and a
 * customer shown 0% of a quota they are in fact near is a support ticket
 * answered by somebody who believes the bar.
 */
export function HostingUsageCard({ accountId }: { accountId: string }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data, isPending, error } = useHostingUsage(accountId)

  return (
    <Card title={t('hosting.usage')} description={t('hosting.usageBody')}>
      <LoadFailure error={error} />

      {isPending ? (
        <Loading />
      ) : data === undefined ? null : (
        <div className="flex flex-col gap-5">
          <Measure label={t('hosting.disk')} measure={data.data.disk} />
          <Measure label={t('hosting.bandwidth')} measure={data.data.bandwidth} />

          <p className="text-xs text-[var(--text-muted)]">
            {data.meta.never_measured
              ? t('hosting.neverMeasured')
              : t('hosting.measuredAt', {
                  when: formatDateTime(data.meta.measured_at ?? '', locale),
                })}
            {data.meta.stale ? ` · ${t('hosting.usageStale')}` : ''}
          </p>
        </div>
      )}
    </Card>
  )
}

function Measure({ label, measure }: { label: string; measure: HostingUsageMeasure }) {
  const { t } = useTranslation()

  const gib = (mib: number) => (mib / 1024).toFixed(mib < 1024 ? 2 : 1)

  /*
   * A bar only where a percentage is a real number: the API sends null unless
   * both halves are known and the quota is a real ceiling, because a
   * percentage of an unknown quota is a fabrication and a percentage of an
   * unlimited one is a division by zero dressed up as a progress bar.
   */
  const percent = measure.used_percent

  return (
    <div>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-medium text-[var(--text-primary)]">{label}</p>

        <p className="text-sm text-[var(--text-secondary)]" dir="ltr">
          {measure.used_mib === null ? (
            <span className="text-[var(--text-muted)]">{t('resource.notAvailable')}</span>
          ) : (
            <>
              {gib(measure.used_mib)} GiB
              {measure.unlimited === true
                ? ` / ${t('hosting.unlimited')}`
                : measure.quota_mib === null
                  ? ''
                  : ` / ${gib(measure.quota_mib)} GiB`}
            </>
          )}
        </p>
      </div>

      {percent === null ? null : (
        <div
          className="mt-2 h-2 overflow-hidden rounded-full bg-[var(--surface-sunken)]"
          role="meter"
          aria-label={label}
          aria-valuenow={percent}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuetext={`${percent.toString()}%`}
        >
          <div
            className={
              percent >= 90
                ? 'h-full bg-[var(--danger-text)]'
                : percent >= 75
                  ? 'h-full bg-[var(--warning-text)]'
                  : 'h-full bg-[var(--text-secondary)]'
            }
            style={{ width: `${Math.min(100, percent).toString()}%` }}
          />
        </div>
      )}

      {/*
        * Said in words as well as drawn, because a bar is not readable by a
        * screen reader beyond its value and because "we have not been told"
        * has no width.
        */}
      {measure.used_mib === null ? (
        <p className="mt-1 text-xs text-[var(--text-muted)]">{t('hosting.notMeasured')}</p>
      ) : measure.quota_mib === null && measure.unlimited !== true ? (
        <p className="mt-1 text-xs text-[var(--text-muted)]">{t('hosting.quotaUnknown')}</p>
      ) : null}
    </div>
  )
}
