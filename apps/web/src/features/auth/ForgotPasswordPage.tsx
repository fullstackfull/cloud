import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Field } from '@/components/Field'
import { api } from '@/lib/api'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const [email, setEmail] = useState('')

  const send = useMutation({
    mutationFn: (address: string) => api.post<unknown>('/password/forgot', { email: address }),
  })

  const displayed = describeError(send.error)

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        send.mutate(email)
      }}
    >
      <header>
        <h1 className="text-2xl font-semibold text-[var(--text-primary)]">
          {t('auth.forgotTitle')}
        </h1>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">{t('auth.forgotSubtitle')}</p>
      </header>

      {displayed !== null ? (
        <Alert tone="error" requestId={displayed.requestId}>
          {displayed.message}
        </Alert>
      ) : null}

      {/*
        The success message is deliberately the same whether or not the address
        belongs to an account. Saying "no such account" here would turn this
        form into a way to find out who has one.
      */}
      {send.isSuccess ? <Alert tone="success">{t('auth.forgotSent')}</Alert> : null}

      <Field
        label={t('common.email')}
        type="email"
        value={email}
        onChange={(event) => { setEmail(event.target.value); }}
        autoComplete="email"
        required
      />

      <Button type="submit" loading={send.isPending} className="w-full">
        {t('auth.sendResetLink')}
      </Button>

      <p className="text-center text-sm">
        <Link to="/sign-in" className="font-medium text-brand-600 hover:underline">
          {t('common.signIn')}
        </Link>
      </p>
    </form>
  )
}
