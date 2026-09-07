import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { useChangePassword } from '@/features/account/useProfile'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function PasswordSection() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const change = useChangePassword()

  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [done, setDone] = useState(false)

  const displayed = describeError(change.error)
  const fieldErrors = displayed?.fields ?? null

  async function submit(event: React.SyntheticEvent) {
    event.preventDefault()
    setDone(false)

    try {
      await change.mutateAsync({
        current_password: current,
        password: next,
        password_confirmation: confirmation,
      })

      // Cleared on success, not held for a second submission: these are
      // credentials, and there is no reason for them to stay in memory.
      setCurrent('')
      setNext('')
      setConfirmation('')
      setDone(true)
    } catch {
      // Rendered from the mutation state below.
    }
  }

  return (
    <Card title={t('security.passwordTitle')} description={t('security.passwordSubtitle')}>
      <form onSubmit={(event) => void submit(event)} className="flex max-w-md flex-col gap-4" noValidate>
        {displayed !== null ? (
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        ) : null}

        {done ? <Alert tone="success">{t('security.passwordChanged')}</Alert> : null}

        <Field
          label={t('security.currentPassword')}
          type="password"
          value={current}
          onChange={(event) => { setCurrent(event.target.value); }}
          autoComplete="current-password"
          required
          error={fieldErrors?.['current_password']?.[0]}
        />

        <Field
          label={t('security.newPassword')}
          type="password"
          value={next}
          onChange={(event) => { setNext(event.target.value); }}
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

        <div>
          <Button type="submit" loading={change.isPending}>
            {t('security.changePassword')}
          </Button>
        </div>

        <p className="text-xs text-[var(--text-muted)]">{t('security.passwordRevokesSessions')}</p>
      </form>
    </Card>
  )
}
