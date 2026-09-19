import { useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { PageHeader } from '@/components/PageHeader'
import { Loading } from '@/components/Loading'
import { useAcceptInvitation, useDeclineInvitation, useInvitationOffer } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The page the link in an invitation mail lands on.
 *
 * Reached with a token and nothing else. Everything it can say about the offer
 * comes from the server, which tells the holder of a token only what the mail
 * already told them — which account, which role, who invited them — and
 * nothing about who else is in the account or what it owns.
 *
 * The three states worth distinguishing:
 *
 *  - **Not signed in.** The route is behind authentication, so this cannot
 *    happen without the router sending them to sign in first, with a return
 *    path back here.
 *  - **Signed in as somebody else.** `is_for_you` is false. Say so plainly:
 *    somebody with two addresses who signed in with the wrong one should be
 *    told that rather than shown a refusal they cannot interpret.
 *  - **Offer already spent.** The read fails, and a token that names nothing
 *    and one that names a withdrawn offer give the same answer on purpose.
 */
export function InvitationPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const describeError = useApiErrorMessage()

  const { token = '' } = useParams<{ token: string }>()
  const { data, isPending, error } = useInvitationOffer(token)
  const accept = useAcceptInvitation()
  const decline = useDeclineInvitation()

  const displayed = describeError(error ?? accept.error ?? decline.error)
  const offer = data?.data ?? null

  return (
    <>
      <PageHeader title={t('invitation.title')} description={t('invitation.subtitle')} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      {isPending ? <Loading /> : null}

      {offer !== null ? (
        <Card title={offer.account ?? t('invitation.anAccount')}>
          <dl className="mb-4 flex flex-col gap-2 text-sm">
            <div className="flex gap-2">
              <dt className="text-[var(--text-muted)]">{t('team.role')}</dt>
              <dd>{t(`team.roles.${offer.role}`)}</dd>
            </div>
            {offer.invited_by !== null ? (
              <div className="flex gap-2">
                <dt className="text-[var(--text-muted)]">{t('team.invitedBy')}</dt>
                <dd>{offer.invited_by}</dd>
              </div>
            ) : null}
          </dl>

          <p className="mb-4 text-sm">{t(`team.roleHints.${offer.role}`)}</p>

          {offer.is_for_you ? (
            <div className="flex gap-2">
              <Button
                loading={accept.isPending}
                onClick={() => {
                  accept.mutate(token, { onSuccess: () => { void navigate('/'); } })
                }}
              >
                {t('invitation.accept')}
              </Button>
              <Button
                variant="ghost"
                loading={decline.isPending}
                onClick={() => {
                  decline.mutate(token, { onSuccess: () => { void navigate('/'); } })
                }}
              >
                {t('invitation.decline')}
              </Button>
            </div>
          ) : (
            <Alert tone="warning" title={t('invitation.signedInAsSomebodyElseTitle')}>
              {t('invitation.signedInAsSomebodyElse')}
            </Alert>
          )}
        </Card>
      ) : null}
    </>
  )
}
