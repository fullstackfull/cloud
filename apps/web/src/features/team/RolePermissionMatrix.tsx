import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { useTeamRoles } from '@/lib/queries'
import type { TeamCapability } from '@/lib/types'

/**
 * What each role may actually do.
 *
 * The audit's AS-12 was that this screen had nothing of the kind: an owner
 * picked "Billing" from a dropdown and discovered what it meant by watching a
 * colleague be refused. The obvious fix is a table written out in this file —
 * and that is the fix this deliberately is not, because a hand-written table
 * of roles and ticks is a promise maintained in a place nobody edits when a
 * permission moves.
 *
 * Everything here comes from `GET /team/roles`, which computes the matrix from
 * the same permission list the API checks before every write. An architecture
 * test asserts that the set of capabilities published and the set enforced are
 * equal in both directions, so a row here cannot describe something the server
 * does not do, and a permission the server enforces cannot go unexplained.
 *
 * ## Shape
 *
 * Capabilities are rows and roles are columns, rather than the other way
 * round. "Can this role pay invoices?" is answered by reading across one row
 * and comparing five cells; the transpose makes the same question five
 * separate lookups. Five columns also fits a phone with one scroll; nine would
 * not fit anything.
 *
 * Every cell says yes or no in words for a screen reader, with the glyph
 * `aria-hidden`. A table whose only content is a tick mark is a table that
 * reads as empty.
 */
export function RolePermissionMatrix() {
  const { t } = useTranslation()
  const { data, isPending, error } = useTeamRoles()

  return (
    <Card title={t('team.permissionsTitle')} description={t('team.permissionsSubtitle')}>
      <LoadFailure error={error} />

      {error !== null ? null : isPending ? (
        <Loading />
      ) : (
        // Its own scroll container, so a five-column table on a 360px phone
        // scrolls itself instead of scrolling the page sideways.
        <div className="overflow-x-auto">
          <table className="w-full min-w-[36rem] border-collapse text-sm">
            <caption className="sr-only">{t('team.permissionsTitle')}</caption>

            <thead>
              <tr className="border-b border-[var(--border-subtle)]">
                <th scope="col" className="py-2 pe-3 text-start font-medium">
                  {t('team.capability')}
                </th>

                {data.data.map((role) => (
                  <th key={role.id} scope="col" className="px-3 py-2 text-start font-medium">
                    {t(`team.roles.${role.id}`)}
                  </th>
                ))}
              </tr>
            </thead>

            <tbody>
              {(data.data[0]?.capabilities ?? []).map((capability: TeamCapability, index: number) => (
                <tr key={capability.id} className="border-b border-[var(--border-subtle)] last:border-0">
                  <th scope="row" className="py-3 pe-3 text-start font-normal align-top">
                    <span className="block text-[var(--text-primary)]">
                      {t(`team.capabilities.${capability.id}`)}
                    </span>
                    <span className="block text-xs text-[var(--text-muted)]">
                      {t(`team.capabilityHints.${capability.id}`)}
                    </span>
                  </th>

                  {data.data.map((role) => {
                    const granted = role.capabilities[index]?.granted === true

                    return (
                      <td key={role.id} className="px-3 py-3 align-top">
                        <span aria-hidden="true" className={granted ? 'text-[var(--accent)]' : 'text-[var(--text-muted)]'}>
                          {granted ? '✓' : '—'}
                        </span>
                        {/*
                          Never a tick alone: a cell whose meaning is carried
                          by a glyph and a colour is a cell that means nothing
                          to a screen reader and nothing to somebody who does
                          not see the colour.
                        */}
                        <span className="sr-only">
                          {granted ? t('common.yes') : t('common.no')}
                        </span>
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="mt-3 text-xs text-[var(--text-muted)]">{t('team.permissionsNote')}</p>
    </Card>
  )
}
