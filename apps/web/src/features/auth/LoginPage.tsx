import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Field } from '@/components/Field'
import { safeRedirect } from '@/lib/safeRedirect'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { challengeTokenFrom, useLogin, useTwoFactorChallenge } from './useAuth'

interface SignInLocationState {
  from?: string
}

export function LoginPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const navigate = useNavigate()
  const location = useLocation()

  // Where the visitor was headed before the guard sent them here, narrowed to
  // somewhere inside this application. The sign-in page is exactly where an
  // attacker would want an open redirect.
  const destination = safeRedirect((location.state as SignInLocationState | null)?.from)

  const login = useLogin()
  const twoFactor = useTwoFactorChallenge()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')

  /*
   * Holding the challenge token in component state rather than storage is
   * deliberate: it is a short-lived, single-use credential, and persisting it
   * would leave it readable on a shared machine after the tab is closed.
   */
  const [challengeToken, setChallengeToken] = useState<string | null>(null)

  const activeError = challengeToken === null ? login.error : twoFactor.error
  const displayed = describeError(activeError)
  const fieldErrors = displayed?.fields ?? null

  async function handlePasswordStage(event: React.SyntheticEvent) {
    event.preventDefault()

    try {
      await login.mutateAsync({ email, password })
      void navigate(destination, { replace: true })
    } catch (error) {
      const token = challengeTokenFrom(error)
      if (token !== null) {
        setChallengeToken(token)
        // Clear the password from state the moment it is no longer needed.
        setPassword('')
      }
    }
  }

  async function handleTwoFactorStage(event: React.SyntheticEvent) {
    event.preventDefault()
    if (challengeToken === null) return

    try {
      await twoFactor.mutateAsync({ challenge_token: challengeToken, code })
      void navigate(destination, { replace: true })
    } catch {
      // The error is rendered from the mutation state below.
    }
  }

  return (
    <div>
      <div>
        <header className="mb-8">
          <h1 className="text-2xl font-semibold text-[var(--text-primary)]">
            {challengeToken === null ? t('auth.signInTitle') : t('auth.twoFactorTitle')}
          </h1>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            {challengeToken === null ? t('auth.signInSubtitle') : t('auth.twoFactorPrompt')}
          </p>
        </header>

        {displayed !== null ? (
          <div className="mb-4">
            <Alert tone="error" requestId={displayed.requestId}>
              {displayed.message}
            </Alert>
          </div>
        ) : null}

        {challengeToken === null ? (
          <form onSubmit={handlePasswordStage} className="flex flex-col gap-4" noValidate>
            <Field
              label={t('common.email')}
              type="email"
              name="email"
              autoComplete="username"
              required
              value={email}
              onChange={(event) => { setEmail(event.target.value); }}
              error={fieldErrors?.['email']?.[0]}
            />

            <Field
              label={t('common.password')}
              type="password"
              name="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(event) => { setPassword(event.target.value); }}
              error={fieldErrors?.['password']?.[0]}
            />

            <Button type="submit" size="lg" loading={login.isPending}>
              {t('common.signIn')}
            </Button>
          </form>
        ) : (
          <form onSubmit={handleTwoFactorStage} className="flex flex-col gap-4" noValidate>
            <Field
              label={t('auth.code')}
              name="code"
              // One-time codes are always Western digits, LTR, and should be
              // offered by the platform's autofill.
              className="technical tracking-widest"
              inputMode="numeric"
              autoComplete="one-time-code"
              autoFocus
              required
              value={code}
              onChange={(event) => { setCode(event.target.value); }}
              error={fieldErrors?.['code']?.[0]}
            />

            <Button type="submit" size="lg" loading={twoFactor.isPending}>
              {t('common.signIn')}
            </Button>
          </form>
        )}

        {challengeToken === null ? (
          <div className="mt-6 flex flex-col gap-2 text-center text-sm">
            <Link to="/forgot-password" className="text-brand-600 hover:underline">
              {t('auth.forgotPassword')}
            </Link>
            <p className="text-[var(--text-secondary)]">
              {t('auth.noAccount')}{' '}
              <Link to="/register" className="font-medium text-brand-600 hover:underline">
                {t('common.register')}
              </Link>
            </p>
          </div>
        ) : null}
      </div>
    </div>
  )
}
