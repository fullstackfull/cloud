import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { SelectField } from '@/components/SelectField'
import { Loading } from '@/components/Loading'
import { useToasts } from '@/components/toastChannel'
import { useCurrentUser } from '@/features/auth/useAuth'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useChangeMemberRole,
  useInviteMember,
  useRemoveMember,
  useResendInvitation,
  useRevokeInvitation,
  useTeamInvitations,
  useTeamMembers,
  useTeamRoles,
  useTransferOwnership,
} from '@/lib/queries'
import type { InvitationStatus, TeamInvitation, TeamMember, TeamRole } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { RoleChangeDialog } from './RoleChangeDialog'
import { RolePermissionMatrix } from './RolePermissionMatrix'

/**
 * An offer nobody has answered is not a problem; one that was withdrawn or ran
 * out is a thing somebody may need to do again. The tones say which is which
 * without the reader parsing the words.
 */
const INVITATION_TONES: Record<InvitationStatus, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
  pending: 'info',
  accepted: 'success',
  declined: 'neutral',
  revoked: 'warning',
  expired: 'warning',
}

/**
 * Who belongs to this account.
 *
 * The screen is deliberately readable by every member and writable by few. A
 * read-only member who cannot see who has access to their servers is worse off
 * than one who can, so the member list is always shown; the invitation panel
 * and every control that changes something appear only when the server would
 * accept them.
 *
 * "Would accept them" is decided from the caller's own role, which the server
 * returns on their profile. It is a display decision and never a security one
 * — every one of these endpoints refuses on its own — so a stale role here
 * costs a 403, not an unauthorised change.
 */
export function TeamPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  /*
   * The account this screen is about, and this person's role in it. Taken
   * from the signed-in user rather than from the team response, because the
   * team response deliberately does not say which of its rows is the caller.
   *
   * A single membership is the only case the portal handles today — there is
   * no account switcher — so the first is the acting one, which is exactly
   * what the server resolves to when no header names an account.
   */
  const { data: user } = useCurrentUser()
  const account = user?.customers[0] ?? null
  const myRole = (account?.role ?? null) as TeamRole | null
  const canManage = myRole === 'owner' || myRole === 'administrator'

  const { data: members, isPending, error: membersError } = useTeamMembers()
  const { data: invitations, error: invitationsError } = useTeamInvitations(canManage)
  const { data: roleMatrix } = useTeamRoles()

  const invite = useInviteMember()
  const resend = useResendInvitation()
  const revoke = useRevokeInvitation()
  const changeRole = useChangeMemberRole()
  const remove = useRemoveMember()
  const transfer = useTransferOwnership()

  const [email, setEmail] = useState('')
  const [role, setRole] = useState<TeamRole>('member')
  const [removing, setRemoving] = useState<TeamMember | null>(null)
  // The role a colleague is about to be given, held until it is confirmed.
  // Picking from the dropdown used to send the change on the change event.
  const [changing, setChanging] = useState<{ member: TeamMember; to: TeamRole } | null>(null)
  const [handingOver, setHandingOver] = useState<TeamMember | null>(null)
  // The open offer about to be taken back. Named in the dialogue by address.
  const [withdrawing, setWithdrawing] = useState<TeamInvitation | null>(null)

  const { announce } = useToasts()

  const displayed = describeError(
    invite.error ?? resend.error ?? revoke.error ?? changeRole.error ?? remove.error,
  )
  const transferError = describeError(transfer.error)

  const assignable = members?.meta.assignable_roles ?? ['administrator', 'billing', 'technical', 'member']

  const memberColumns: Array<Column<TeamMember>> = [
    {
      key: 'person',
      header: t('team.person'),
      cell: (member) => (
        <div>
          <div>{member.name ?? '—'}</div>
          <div dir="ltr" className="technical text-xs text-[var(--text-muted)]">
            {member.email ?? '—'}
          </div>
        </div>
      ),
    },
    {
      key: 'role',
      header: t('team.role'),
      cell: (member) =>
        canManage && member.role !== 'owner' ? (
          /*
           * Choosing a role opens the confirmation; it does not send it. The
           * select's own value stays on the member's current role until the
           * server has agreed, so a dropdown that reads "Administrator" always
           * means the person is one.
           */
          <SelectField
            label={t('team.role')}
            labelHidden
            value={member.role}
            disabled={changeRole.isPending}
            onChange={(event) => {
              const picked = event.target.value as TeamRole

              if (picked !== member.role) setChanging({ member, to: picked })
            }}
            options={assignable.map((option) => ({
              value: option,
              label: t(`team.roles.${option}`),
            }))}
          />
        ) : (
          <Badge tone={member.role === 'owner' ? 'info' : 'neutral'}>{t(`team.roles.${member.role}`)}</Badge>
        ),
    },
    {
      key: 'invitedBy',
      header: t('team.invitedBy'),
      cell: (member) => member.invited_by ?? <span className="text-[var(--text-muted)]">—</span>,
    },
    {
      key: 'joined',
      header: t('team.joined'),
      ltr: true,
      cell: (member) =>
        member.joined_at === null ? (
          <span className="text-[var(--text-muted)]">{t('team.notYetJoined')}</span>
        ) : (
          formatDateTime(member.joined_at, locale)
        ),
    },
    {
      key: 'actions',
      header: '',
      cell: (member) =>
        !canManage || member.role === 'owner' ? null : (
          <div className="flex gap-2">
            {myRole === 'owner' ? (
              <Button size="sm" variant="ghost" onClick={() => { setHandingOver(member); }}>
                {t('team.makeOwner')}
              </Button>
            ) : null}
            <Button size="sm" variant="ghost" onClick={() => { setRemoving(member); }}>
              {t('team.remove')}
            </Button>
          </div>
        ),
    },
  ]

  const invitationColumns: Array<Column<TeamInvitation>> = [
    {
      key: 'email',
      header: t('team.invitedAddress'),
      cell: (invitation) => (
        <span dir="ltr" className="technical">
          {invitation.email}
        </span>
      ),
    },
    {
      key: 'role',
      header: t('team.role'),
      cell: (invitation) => t(`team.roles.${invitation.role}`),
    },
    {
      key: 'status',
      header: t('team.status'),
      cell: (invitation) => (
        <Badge tone={INVITATION_TONES[invitation.status]}>{t(`team.statuses.${invitation.status}`)}</Badge>
      ),
    },
    {
      key: 'expires',
      header: t('team.expires'),
      ltr: true,
      cell: (invitation) => formatDateTime(invitation.expires_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (invitation) =>
        invitation.status !== 'pending' ? null : (
          <div className="flex gap-2">
            <Button
              size="sm"
              variant="ghost"
              loading={resend.isPending && resend.variables === invitation.id}
              onClick={() => {
                // A second silent success is indistinguishable from a button
                // that does nothing, which is what this one looked like.
                resend.mutate(invitation.id, {
                  onSuccess: () => {
                    announce({
                      id: `invitation:${invitation.id}`,
                      tone: 'success',
                      title: t('team.invitationResent', { email: invitation.email }),
                    })
                  },
                })
              }}
            >
              {t('team.resend')}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              loading={revoke.isPending && revoke.variables === invitation.id}
              onClick={() => { setWithdrawing(invitation); }}
            >
              {t('team.revoke')}
            </Button>
          </div>
        ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.team')} description={t('team.subtitle')} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <div className="flex flex-col gap-4">
        {/*
          Before the list, because the question it answers — what does this
          role mean — is asked before a role is picked, not after.
        */}
        <RolePermissionMatrix />

        <Card title={t('team.membersTitle')} description={t('team.membersSubtitle')}>
          {/*
            * Loading, failed and empty are three different facts. A refused
            * read used to render as "Nobody else has access to this account",
            * which is the one thing a refused read cannot know.
            */}
          <LoadFailure error={membersError} />
          {membersError !== null ? null : isPending ? (
            <Loading />
          ) : (
            <DataTable
              columns={memberColumns}
              rows={members.data}
              rowKey={(member) => member.id}
              empty={t('team.noMembers')}
            />
          )}
        </Card>

        {canManage ? (
          <>
            <Card title={t('team.inviteTitle')} description={t('team.inviteSubtitle')}>
              <form
                className="flex max-w-md flex-col gap-3"
                noValidate
                onSubmit={(event) => {
                  event.preventDefault()
                  invite.mutate(
                    { email, role },
                    {
                      onSuccess: () => {
                        setEmail('')
                        setRole('member')
                      },
                    },
                  )
                }}
              >
                <Field
                  label={t('team.invitedAddress')}
                  type="email"
                  value={email}
                  onChange={(event) => { setEmail(event.target.value); }}
                  required
                  error={displayed?.fields?.['email']?.[0]}
                />

                {/*
                  The hint changes with the choice, so the owner reads what the
                  role means before sending the invitation rather than after
                  the colleague finds out.
                */}
                <SelectField
                  label={t('team.role')}
                  hint={t(`team.roleHints.${role}`)}
                  value={role}
                  onChange={(event) => { setRole(event.target.value as TeamRole); }}
                  options={assignable.map((option) => ({
                    value: option,
                    label: t(`team.roles.${option}`),
                  }))}
                />

                <div>
                  <Button type="submit" loading={invite.isPending}>
                    {t('team.sendInvitation')}
                  </Button>
                </div>
              </form>
            </Card>

            <Card title={t('team.invitationsTitle')} description={t('team.invitationsSubtitle')}>
              <LoadFailure error={invitationsError} />
              {invitationsError !== null ? null : (
                <DataTable
                  columns={invitationColumns}
                  rows={invitations?.data ?? []}
                  rowKey={(invitation) => invitation.id}
                  empty={t('team.noInvitations')}
                />
              )}
            </Card>
          </>
        ) : null}
      </div>

      <ConfirmDialog
        open={withdrawing !== null}
        title={t('team.withdrawDialog.title', { email: withdrawing?.email ?? '' })}
        body={<p>{t('team.withdrawDialog.body')}</p>}
        confirmLabel={t('team.withdrawDialog.confirmLabel')}
        loading={revoke.isPending}
        onConfirm={() => {
          if (withdrawing === null) return

          revoke.mutate(withdrawing.id, { onSettled: () => { setWithdrawing(null); } })
        }}
        onCancel={() => { setWithdrawing(null); }}
      />

      <ConfirmDialog
        open={removing !== null}
        title={t('team.removeTitle')}
        body={
          <div className="flex flex-col gap-2">
            <p>{t('team.removeBody', { name: removing?.name ?? removing?.email ?? '' })}</p>

            {/*
              What actually ends, and what does not. Said because the two are
              different and a customer removing a departing colleague needs to
              know which: their access to this account goes immediately, and
              any API token they minted keeps working until somebody revokes
              it — which is the account's own list, not theirs.
            */}
            <p>{t('team.removeAccessEnds')}</p>
            <p className="text-[var(--warning-text)]">{t('team.removeTokensRemain')}</p>
          </div>
        }
        confirmLabel={t('team.remove')}
        loading={remove.isPending}
        onCancel={() => { setRemoving(null); }}
        onConfirm={() => {
          if (removing !== null) {
            remove.mutate(removing.id, { onSuccess: () => { setRemoving(null); } })
          }
        }}
      />

      {/*
        Handing the account over takes the account's own name typed back — the
        same device the irreversible cancellation and the reinstall use, and
        there is no undo that does not depend on the goodwill of whoever now
        owns it.

        It used to be the account's ULID. Both values are on this screen, so
        neither was ever a secret and nothing is weakened by the change; what
        is different is that somebody typing their own company's name is
        recognising what they are giving away, and somebody copying
        twenty-six characters of base32 is not.
      */}
      <ConfirmDialog
        open={handingOver !== null}
        title={t('team.transferTitle')}
        body={
          <div className="flex flex-col gap-2">
            <p>{t('team.transferBody', { name: handingOver?.name ?? handingOver?.email ?? '' })}</p>
            <p>{t('team.transferWhatChanges')}</p>
          </div>
        }
        requiredPhrase={account?.display_name ?? ''}
        requiredPhraseLabel={t('team.transferConfirmLabel', { account: account?.display_name ?? '' })}
        confirmLabel={t('team.makeOwner')}
        loading={transfer.isPending}
        error={transferError?.message}
        onCancel={() => { setHandingOver(null); }}
        onConfirm={(phrase) => {
          if (handingOver !== null) {
            transfer.mutate(
              { member_id: handingOver.id, confirm_account_name: phrase },
              {
                onSuccess: () => {
                  setHandingOver(null)
                  announce({
                    id: 'team:ownership',
                    tone: 'success',
                    title: t('team.transferDone', {
                      name: handingOver.name ?? handingOver.email ?? '',
                    }),
                  })
                },
              },
            )
          }
        }}
      />

      <RoleChangeDialog
        member={changing?.member ?? null}
        to={changing?.to ?? null}
        roles={roleMatrix?.data ?? []}
        loading={changeRole.isPending}
        error={describeError(changeRole.error)?.message}
        onCancel={() => { setChanging(null); }}
        onConfirm={() => {
          if (changing === null) return

          changeRole.mutate(
            { id: changing.member.id, role: changing.to },
            {
              onSuccess: () => {
                announce({
                  id: `team:role:${changing.member.id}`,
                  tone: 'success',
                  title: t('team.roleChange.done', {
                    name: changing.member.name ?? changing.member.email ?? '',
                    role: t(`team.roles.${changing.to}`),
                  }),
                })
                setChanging(null)
              },
            },
          )
        }}
      />
    </>
  )
}
