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

  /*
   * Recovery codes issued by the enrolment that just completed.
   *
   * Held here rather than inside the enrolment panel, because confirming
   * enrolment refetches the user, the account flips to "enabled", and the
   * panel that was showing the codes unmounts — the codes were on screen for
   * one render and gone, and they are shown exactly once. They stay until
   * the customer says they have saved them.
   */
  const [issued, setIssued] = useState<string[] | null>(null)

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
      {issued !== null ? (
        <div className="flex flex-col gap-4">
          <RecoveryCodes codes={issued} />
          <div>
            <Button variant="secondary" onClick={() => { setIssued(null); }}>
              {t('security.recoveryCodesSaved')}
            </Button>
          </div>
        </div>
      ) : enabled ? (
        <EnabledPanel />
      ) : (
        <EnrolmentPanel onEnrolled={setIssued} />
      )}
    </Card>
  )
}

function EnrolmentPanel({ onEnrolled }: { onEnrolled: (codes: string[]) => void }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const begin = useBeginTwoFactorEnrolment()
  const confirm = useConfirmTwoFactor()

  const [enrolment, setEnrolment] = useState<TwoFactorEnrolment | null>(null)
  const [code, setCode] = useState('')

  /*
   * Whether the password step is open, and the password typed into it.
   *
   * The server requires the current password to begin enrolment, for the
   * same reason it requires it to end it: a session that has been taken over
   * must not be able to change how the account is signed in to. The step is
   * asked for here rather than assumed — the disable flow below already
   * works this way — and the password is held only until the request is
   * sent.
   */
  const [askingPassword, setAskingPassword] = useState(false)
  const [password, setPassword] = useState('')

  const displayed = describeError(begin.error ?? confirm.error)

  if (enrolment === null) {
    return (
      <div className="flex flex-col gap-4">
        {displayed !== null ? (
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        ) : null}

        <p className="text-sm text-[var(--text-secondary)]">{t('security.twoFactorWhy')}</p>

        {askingPassword ? (
          <form
            className="flex max-w-md flex-col gap-3"
            noValidate
            onSubmit={(event) => {
              event.preventDefault()
              begin.mutate(password, {
                onSuccess: (next) => {
                  setEnrolment(next)
                  setPassword('')
                  setAskingPassword(false)
                },
              })
            }}
          >
            <Field
              label={t('security.currentPassword')}
              type="password"
              value={password}
              onChange={(event) => { setPassword(event.target.value); }}
              autoComplete="current-password"
              required
              hint={t('security.enableTwoFactorHint')}
              error={displayed?.fields?.['current_password']?.[0]}
            />

            <div className="flex gap-2">
              <Button type="submit" loading={begin.isPending}>
                {t('security.enableContinue')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  setAskingPassword(false)
                  setPassword('')
                  begin.reset()
                }}
              >
                {t('common.cancel')}
              </Button>
            </div>
          </form>
        ) : (
          <div>
            <Button onClick={() => { setAskingPassword(true); }}>
              {t('security.enableTwoFactor')}
            </Button>
          </div>
        )}
      </div>
    )
  }

  return (
    <form
      className="flex max-w-md flex-col gap-4"
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        confirm.mutate(code, { onSuccess: onEnrolled })
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
