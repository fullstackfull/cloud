import { useTranslation } from 'react-i18next'

import { ConfirmDialog } from '@/components/ConfirmDialog'
import type { TeamMember, TeamRole, TeamRoleCapabilities } from '@/lib/types'

interface RoleChangeDialogProps {
  member: TeamMember | null
  /** The role the owner has picked, which has not been sent anywhere yet. */
  to: TeamRole | null
  roles: TeamRoleCapabilities[]
  loading: boolean
  error?: string | undefined
  onConfirm: () => void
  onCancel: () => void
}

/**
 * What changes when a colleague's role changes, before it changes.
 *
 * The audit found a bare `<select onChange>` wired straight to the mutation:
 * a colleague's access altered on a stray scroll wheel, with no statement of
 * what had happened and no confirmation that it had. The consequence is not
 * dramatic — it is reversible by changing it back — but it is invisible, and
 * invisible is how an account ends up with two people who can spend money and
 * nobody who remembers granting it.
 *
 * ## What it says
 *
 * Not "are you sure", which asks a question the customer cannot answer without
 * knowing the answer already. The dialogue lists **what this person will gain
 * and what they will lose**, computed by diffing the two roles' capability
 * lists — from the server's matrix, so it is the same source the endpoint
 * enforces from.
 *
 * ## How hard it is to confirm
 *
 * Graded, per the confirmation policy the portal has used since Wave 0. A role
 * change is reversible, so it is a plain dialogue with a plain button — no
 * typed phrase, which is reserved for things that cannot be undone, and which
 * would teach customers to type words into boxes to get on with their day.
 *
 * The one thing that earns extra weight is a promotion that hands over control
 * of membership itself: somebody who can manage members can remove the person
 * promoting them. That gets a stated warning rather than a harder ritual,
 * because the risk is misunderstanding rather than mis-clicking.
 */
export function RoleChangeDialog({
  member,
  to,
  roles,
  loading,
  error,
  onConfirm,
  onCancel,
}: RoleChangeDialogProps) {
  const { t } = useTranslation()

  const from = member?.role ?? null
  const before = roles.find((role) => role.id === from)
  const after = roles.find((role) => role.id === to)

  const gained = difference(after, before)
  const lost = difference(before, after)

  // Handing over the ability to manage members includes the ability to remove
  // whoever handed it over. Worth a sentence; not worth a ritual.
  const handsOverMembership = gained.includes('manage_members')

  const who = member?.name ?? member?.email ?? ''

  return (
    <ConfirmDialog
      open={member !== null && to !== null}
      tone="primary"
      title={t('team.roleChange.title', { name: who })}
      body={
        <div className="flex flex-col gap-3">
          <p>
            {t('team.roleChange.summary', {
              name: who,
              from: from === null ? '' : t(`team.roles.${from}`),
              to: to === null ? '' : t(`team.roles.${to}`),
            })}
          </p>

          {gained.length === 0 ? null : (
            <div>
              <p className="font-medium text-[var(--text-primary)]">
                {t('team.roleChange.gains')}
              </p>
              <ul className="mt-1 list-disc ps-5">
                {gained.map((id) => (
                  <li key={id}>{t(`team.capabilities.${id}`)}</li>
                ))}
              </ul>
            </div>
          )}

          {lost.length === 0 ? null : (
            <div>
              <p className="font-medium text-[var(--text-primary)]">{t('team.roleChange.loses')}</p>
              <ul className="mt-1 list-disc ps-5">
                {lost.map((id) => (
                  <li key={id}>{t(`team.capabilities.${id}`)}</li>
                ))}
              </ul>
            </div>
          )}

          {gained.length === 0 && lost.length === 0 ? (
            <p>{t('team.roleChange.noChange')}</p>
          ) : null}

          {handsOverMembership ? (
            <p className="text-[var(--warning-text)]">{t('team.roleChange.membershipWarning')}</p>
          ) : null}

          <p className="text-xs text-[var(--text-muted)]">{t('team.roleChange.whenItApplies')}</p>
        </div>
      }
      confirmLabel={t('team.roleChange.confirm')}
      loading={loading}
      error={error}
      onConfirm={onConfirm}
      onCancel={onCancel}
    />
  )
}

/**
 * Capability ids the first role has and the second does not.
 *
 * Both halves come from the server's own matrix, so "gains" and "loses" are
 * differences in enforced authorization rather than in a sentence somebody
 * wrote about it.
 */
function difference(
  role: TeamRoleCapabilities | undefined,
  other: TeamRoleCapabilities | undefined,
): string[] {
  if (role === undefined || other === undefined) return []

  const theirs = new Set(
    other.capabilities.filter((capability) => capability.granted).map((capability) => capability.id),
  )

  return role.capabilities
    .filter((capability) => capability.granted && ! theirs.has(capability.id))
    .map((capability) => capability.id)
}
