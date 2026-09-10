import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { useCurrentUser } from '@/features/auth/useAuth'
import { useResendVerificationEmail } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/** How long the resend button stays disabled after a request, in seconds. */
const COOLDOWN_SECONDS = 60

/**
 * Where a verification link lands, and where an unverified customer is sent.
 *
 * Before this page existed, the verification link opened a JSON document in
 * the customer's browser — on the single most important link the platform ever
 * sends — and an unverified customer who wanted another one had nowhere to ask
 * for it.
 *
 * The status in the query string says what the *server* did with the link:
 * verified, already verified, an address that does not match, or a signature
 * that has expired. It is read to choose a sentence and for nothing else. This
 * page cannot make an address verified, and it does not claim an address is
 * verified on the strength of a query parameter — the account's own
 * `email_verified` flag, which comes from the API, is what the rest of the
 * portal reads.
 */
export function VerifyEmailPage() {
  const { t } = useTranslation()
  const [searchParams] = useSearchParams()
  const { data: user = null } = useCurrentUser()
  const describeError = useApiErrorMessage()

  const resend = useResendVerificationEmail()
  const resendError = describeError(resend.error)

  const [cooldown, setCooldown] = useState(0)

  useEffect(() => {
    if (cooldown <= 0) return

    const timer = window.setTimeout(() => { setCooldown((seconds) => seconds - 1); }, 1000)

    return () => { window.clearTimeout(timer); }
  }, [cooldown])

  const status = searchParams.get('status')
  const verified = user?.email_verified === true

  return (
    <Card title={t('verifyEmail.title')}>
      <div className="flex flex-col gap-4">
        {status === 'verified' ? (
          <Alert tone="success">{t('verifyEmail.confirmed')}</Alert>
        ) : status === 'already_verified' ? (
          <Alert tone="info">{t('verifyEmail.alreadyConfirmed')}</Alert>
        ) : status === 'expired' ? (
          <Alert tone="warning">{t('verifyEmail.expired')}</Alert>
        ) : status === 'invalid' ? (
          <Alert tone="error">{t('verifyEmail.invalid')}</Alert>
        ) : (
          <p className="text-sm text-[var(--text-secondary)]">{t('verifyEmail.waiting')}</p>
        )}

        {/*
          The address the link went to, so a customer who mistyped it can see
          the typo. It is their own address and they are signed in; nothing is
          disclosed by showing it back to them.
        */}
        {user !== null ? (
          <p className="text-sm">
            {t('verifyEmail.sentTo')}{' '}
            <span className="technical" dir="ltr">
              {user.email}
            </span>
          </p>
        ) : null}

        {resendError !== null ? (
          <Alert tone="error" requestId={resendError.requestId}>
            {resendError.message}
          </Alert>
        ) : null}

        {/*
          Success is stated separately from the cooldown: "sent" answers what
          happened, and the countdown answers why the button is not available.
        */}
        {resend.isSuccess && cooldown > 0 ? (
          <Alert tone="success">{t('verifyEmail.resent')}</Alert>
        ) : null}

        {verified ? (
          <div className="flex flex-wrap gap-2">
            <Link
              to="/"
              className="inline-flex h-10 items-center rounded-lg bg-brand-600 px-4 text-sm text-white"
            >
              {t('verifyEmail.continue')}
            </Link>
          </div>
        ) : user === null ? (
          <div className="flex flex-wrap gap-2">
            <Link
              to="/sign-in"
              className="inline-flex h-10 items-center rounded-lg bg-brand-600 px-4 text-sm text-white"
            >
              {t('verifyEmail.signIn')}
            </Link>
          </div>
        ) : (
          <div className="flex flex-col gap-2">
            <Button
              loading={resend.isPending}
              disabled={cooldown > 0}
              onClick={() => {
                resend.mutate(undefined, { onSuccess: () => { setCooldown(COOLDOWN_SECONDS); } })
              }}
            >
              {cooldown > 0
                ? t('verifyEmail.resendIn', { seconds: cooldown })
                : t('verifyEmail.resend')}
            </Button>

            <p className="text-xs text-[var(--text-muted)]">{t('verifyEmail.checkSpam')}</p>
          </div>
        )}
      </div>
    </Card>
  )
}
