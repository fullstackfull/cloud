import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import {
  RESOURCE_FAMILIES,
  familyForServiceKind,
  pathForResource,
} from '@/features/resources/resourcePaths'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useServices } from '@/lib/queries'
import type { Service } from '@/lib/types'
import { useUrlPage } from '@/lib/urlState'

/**
 * Everything this account owns, in one list, with a way into each.
 *
 * The audit found this page next to useless: every row said the plan's name,
 * so two servers bought on the same plan were two identical rows, and no row
 * led anywhere. Both are fixed by facts the API now publishes — the service's
 * own identity (its hostname, primary domain or serial) and the family it
 * belongs to — so a row names the thing and links to its page.
 *
 * The plan name stays, beside the identity rather than instead of it: it is
 * what the customer bought and what the invoice says.
 *
 * Nothing here guesses. A service whose identity the platform does not have
 * yet — one still being built — shows its plan and no link, because there is
 * nothing to open.
 */
export function ServicesPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  // W5.7: in the address bar rather than in component state, so a refresh
  // stays on this page and Back returns to it from whatever the customer
  // opened. The one mechanism is in `useUrlPage`.
  const [page, setPage] = useUrlPage()
  const { data, isPending, error: readError } = useServices(page)

  const columns: Array<Column<Service>> = [
    {
      key: 'identity',
      header: t('services.resource'),
      cell: (service) => {
        /*
         * The handle the API published, never the service's own id: the
         * machine, the account and the chassis each have their own, and a
         * link assembled from the service id would 404.
         */
        const to = destinationFor(service)

        return (
          <div className="min-w-0">
            {service.identity === null ? (
              <p className="font-medium text-[var(--text-primary)]">
                {t('services.beingBuilt')}
              </p>
            ) : to === null ? (
              <p className="technical font-medium text-[var(--text-primary)]" dir="ltr">
                {service.identity}
              </p>
            ) : (
              <Link
                to={to}
                className="technical font-medium text-[var(--text-primary)] hover:underline"
                dir="ltr"
              >
                {service.identity}
              </Link>
            )}

            <p className="text-xs text-[var(--text-muted)]">
              {service.label ?? t('services.unnamed')}
            </p>
          </div>
        )
      },
    },
    {
      key: 'kind',
      header: t('services.kind'),
      cell: (service) => {
        const family = familyForServiceKind(service.kind)

        // The family's own name in the customer's vocabulary — "Cloud VPS" —
        // falling back to the commercial kind for anything the portal has no
        // family for.
        return family === null ? service.kind : t(RESOURCE_FAMILIES[family].labelKey)
      },
    },
    {
      key: 'state',
      header: t('services.state'),
      cell: (service) => <StatusBadge status={service.state} />,
    },
    {
      key: 'retention',
      header: t('services.dataUntil'),
      cell: (service) => (
        /*
         * The deadline, in the list rather than one click in. A customer with
         * six services and one ending needs to see which one from here — and a
         * portal that knows the date and shows it only on a detail page is
         * keeping a deadline to itself.
         */
        <span className="text-xs text-[var(--text-muted)]">
          {service.retention_ends_at === null
            ? '—'
            : formatDate(service.retention_ends_at, locale)}
        </span>
      ),
    },
    {
      key: 'since',
      header: t('services.activated'),
      cell: (service) =>
        service.activated_at === null ? '—' : formatDate(service.activated_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (service) => {
        const to = destinationFor(service)

        if (to === null) return null

        return (
          <div className="flex justify-end">
            <Link to={to} className="text-sm underline">
              {t('resource.open')}
            </Link>
          </div>
        )
      },
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.services')} description={t('services.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.services')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(service) => service.id}
              empty={t('services.empty')}
            />
            <Paginator
              page={data?.meta.page ?? 1}
              lastPage={data?.meta.last_page ?? 1}
              total={data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  )
}

/** Where a service's row leads, or null when there is nothing to open yet. */
function destinationFor(service: Service): string | null {
  if (service.resource === null) return null

  return pathForResource(service.resource.kind, service.resource.id)
}
