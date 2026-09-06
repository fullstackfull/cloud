import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Field } from '@/components/Field'
import { api } from '@/lib/api'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

interface ResetPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export function ResetPasswordPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const [params] = useSearchParams()

  // Both arrive in the link the customer was emailed. Neither is ever stored.
  const token = params.get('token') ?? ''
  const emailFromLink = params.get('email') ?? ''

  const [email, setEmail] = useState(emailFromLink)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

  const reset = useMutation({
    mutationFn: (payload: ResetPayload) => api.post<null>('/password/reset', payload),
  })

  const displayed = describeError(reset.error)
  const fieldErrors = displayed?.fields ?? null

  if (token === '') {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="error">{t('auth.resetLinkInvalid')}</Alert>
        <Link to="/forgot-password" className="text-sm font-medium text-brand-600 hover:underline">
          {t('auth.forgotTitle')}
        </Link>
      </div>
    )
  }

  if (reset.isSuccess) {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="success">{t('auth.resetDone')}</Alert>
        <Link to="/sign-in" className="text-sm font-medium text-brand-600 hover:underline">
          {t('common.signIn')}
        </Link>
      </div>
    )
  }

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        reset.mutate({ token, email, password, password_confirmation: confirmation })
      }}
    >
      <header>
        <h1 className="text-2xl font-semibold text-[var(--text-primary)]">{t('auth.resetTitle')}</h1>
      </header>

      {displayed !== null ? (
        <Alert tone="error" requestId={displayed.requestId}>
          {displayed.message}
        </Alert>
      ) : null}

      <Field
        label={t('common.email')}
        type="email"
        value={email}
        onChange={(event) => { setEmail(event.target.value); }}
        autoComplete="email"
        required
        error={fieldErrors?.['email']?.[0]}
      />

      <Field
        label={t('security.newPassword')}
        type="password"
        value={password}
        onChange={(event) => { setPassword(event.target.value); }}
        autoComplete="new-password"
        required
        error={fieldErrors?.['password']?.[0]}
      />

      <Field
        label={t('security.confirmPassword')}
        type="password"
        value={confirmation}
        onChange={(event) => { setConfirmation(event.target.value); }}
        autoComplete="new-password"
        required
      />

      <Button type="submit" loading={reset.isPending} className="w-full">
        {t('auth.resetSubmit')}
      </Button>
    </form>
  )
}
