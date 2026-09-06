import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Field } from '@/components/Field'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { challengeTokenFrom, useLogin, useTwoFactorChallenge } from './useAuth'

export function LoginPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

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
    } catch {
      // The error is rendered from the mutation state below.
    }
  }

  return (
    <main className="flex min-h-dvh items-center justify-center bg-[var(--surface)] px-4 py-12">
      <div className="w-full max-w-sm">
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
      </div>
    </main>
  )
}
