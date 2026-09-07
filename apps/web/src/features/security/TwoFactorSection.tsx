import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { RecoveryCodes } from '@/components/RecoveryCodes'
import { useCurrentUser } from '@/features/auth/useAuth'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import {
  useBeginTwoFactorEnrolment,
  useConfirmTwoFactor,
  useDisableTwoFactor,
  useRegenerateRecoveryCodes,
  type TwoFactorEnrolment,
} from './useTwoFactor'

export function TwoFactorSection() {
  const { t } = useTranslation()
  const { data: user } = useCurrentUser()

  const enabled = user?.two_factor_enabled === true

  return (
    <Card
      title={t('security.twoFactor')}
      description={t('security.twoFactorSubtitle')}
      actions={
        enabled ? (
          <Badge tone="success">{t('security.enabled')}</Badge>
        ) : (
          <Badge tone="warning">{t('security.disabled')}</Badge>
        )
      }
    >
      {enabled ? <EnabledPanel /> : <EnrolmentPanel />}
    </Card>
  )
}

function EnrolmentPanel() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const begin = useBeginTwoFactorEnrolment()
  const confirm = useConfirmTwoFactor()

  const [enrolment, setEnrolment] = useState<TwoFactorEnrolment | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)

  const displayed = describeError(begin.error ?? confirm.error)

  if (codes !== null) {
    return <RecoveryCodes codes={codes} />
  }

  if (enrolment === null) {
    return (
      <div className="flex flex-col gap-4">
        {displayed !== null ? (
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        ) : null}

        <p className="text-sm text-[var(--text-secondary)]">{t('security.twoFactorWhy')}</p>

        <div>
          <Button
            loading={begin.isPending}
            onClick={() => {
              begin.mutate(undefined, { onSuccess: setEnrolment })
            }}
          >
            {t('security.enableTwoFactor')}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <form
      className="flex max-w-md flex-col gap-4"
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        confirm.mutate(code, { onSuccess: setCodes })
      }}
    >
      {displayed !== null ? (
        <Alert tone="error" requestId={displayed.requestId}>
          {displayed.message}
        </Alert>
      ) : null}

      <ol className="flex list-decimal flex-col gap-3 ps-5 text-sm text-[var(--text-secondary)]">
        <li>{t('security.twoFactorStepScan')}</li>
        <li>
          {t('security.twoFactorStepManual')}
          <code
            dir="ltr"
            className="technical mt-1 block rounded-md border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-2 text-xs select-all"
          >
            {enrolment.secret}
          </code>
        </li>
        <li>{t('security.twoFactorStepConfirm')}</li>
      </ol>

      <Field
        label={t('auth.code')}
        value={code}
        onChange={(event) => { setCode(event.target.value); }}
        inputMode="numeric"
        autoComplete="one-time-code"
        dir="ltr"
        maxLength={32}
        required
      />

      <div className="flex gap-2">
        <Button type="submit" loading={confirm.isPending}>
          {t('common.confirm')}
        </Button>
        <Button type="button" variant="ghost" onClick={() => { setEnrolment(null); }}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}

function EnabledPanel() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const disable = useDisableTwoFactor()
  const regenerate = useRegenerateRecoveryCodes()

  const [password, setPassword] = useState('')
  const [intent, setIntent] = useState<'disable' | 'regenerate' | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)

  const displayed = describeError(disable.error ?? regenerate.error)

  function submit(event: React.SyntheticEvent) {
    event.preventDefault()

    if (intent === 'disable') {
      disable.mutate(password, { onSuccess: () => { setPassword(''); } })
      return
    }

    regenerate.mutate(password, {
      onSuccess: (next) => {
        setCodes(next)
        setPassword('')
        setIntent(null)
      },
    })
  }

  if (codes !== null) {
    return <RecoveryCodes codes={codes} />
  }

  return (
    <div className="flex flex-col gap-4">
      {displayed !== null ? (
        <Alert tone="error" requestId={displayed.requestId}>
          {displayed.message}
        </Alert>
      ) : null}

      <p className="text-sm text-[var(--text-secondary)]">{t('security.twoFactorActive')}</p>

      {intent === null ? (
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => { setIntent('regenerate'); }}>
            {t('security.regenerateRecoveryCodes')}
          </Button>
          <Button variant="danger" onClick={() => { setIntent('disable'); }}>
            {t('security.disableTwoFactor')}
          </Button>
        </div>
      ) : (
        <form onSubmit={submit} className="flex max-w-md flex-col gap-3" noValidate>
          {/*
            The current password is required by the server for both operations.
            It is asked for here rather than assumed, because a session that has
            been taken over must not be able to turn off the control that would
            have stopped it.
          */}
          <Field
            label={t('security.currentPassword')}
            type="password"
            value={password}
            onChange={(event) => { setPassword(event.target.value); }}
            autoComplete="current-password"
            required
            hint={
              intent === 'disable'
                ? t('security.disableTwoFactorHint')
                : t('security.regenerateHint')
            }
          />

          <div className="flex gap-2">
            <Button
              type="submit"
              variant={intent === 'disable' ? 'danger' : 'primary'}
              loading={disable.isPending || regenerate.isPending}
            >
              {intent === 'disable' ? t('security.disableTwoFactor') : t('common.confirm')}
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setIntent(null)
                setPassword('')
              }}
            >
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      )}
    </div>
  )
}
