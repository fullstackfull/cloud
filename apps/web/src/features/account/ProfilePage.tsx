import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { PageHeader } from '@/components/PageHeader'
import { useCurrentUser } from '@/features/auth/useAuth'
import { SUPPORTED_LOCALES, changeLocale, isSupportedLocale } from '@/i18n'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { useUpdateProfile } from './useProfile'

const LOCALE_LABELS: Record<string, string> = { en: 'English', ar: 'العربية' }

export function ProfilePage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const { data: user } = useCurrentUser()
  const update = useUpdateProfile()

  const [name, setName] = useState('')
  const [locale, setLocale] = useState('en')
  const [timezone, setTimezone] = useState('UTC')
  const [phone, setPhone] = useState('')
  const [saved, setSaved] = useState(false)

  // Seeded from the server rather than held as a second source of truth: a
  // profile edited in another tab must not be silently overwritten by a stale
  // form.
  useEffect(() => {
    if (user === null || user === undefined) return
    setName(user.name)
    setLocale(user.locale)
    setTimezone(user.timezone)
    setPhone(user.phone ?? '')
  }, [user])

  const displayed = describeError(update.error)
  const fieldErrors = displayed?.fields ?? null

  async function submit(event: React.SyntheticEvent) {
    event.preventDefault()
    setSaved(false)

    try {
      await update.mutateAsync({
        name,
        locale,
        timezone,
        // An empty field means "no phone number", which is a null rather than
        // an empty string: the column is nullable and the validator rejects ''.
        phone: phone.trim() === '' ? null : phone.trim(),
      })
      setSaved(true)

      // The interface follows the preference immediately; waiting for a reload
      // to speak the customer's language would be a strange thing to do.
      if (isSupportedLocale(locale)) await changeLocale(locale)
    } catch {
      // Rendered from the mutation state below.
    }
  }

  if (user === null || user === undefined) return null

  return (
    <>
      <PageHeader title={t('nav.profile')} description={t('account.profileSubtitle')} />

      <div className="max-w-xl">
        <Card>
          <form onSubmit={(event) => void submit(event)} className="flex flex-col gap-4" noValidate>
            {displayed !== null ? (
              <Alert tone="error" requestId={displayed.requestId}>
                {displayed.message}
              </Alert>
            ) : null}

            {saved ? <Alert tone="success">{t('account.profileSaved')}</Alert> : null}

            <Field
              label={t('account.name')}
              value={name}
              onChange={(event) => { setName(event.target.value); }}
              autoComplete="name"
              required
              error={fieldErrors?.['name']?.[0]}
            />

            <Field
              label={t('common.email')}
              type="email"
              value={user.email}
              readOnly
              disabled
              hint={t('account.emailImmutable')}
            />

            <div className="flex flex-col gap-1.5">
              <label
                htmlFor="profile-locale"
                className="text-sm font-medium text-[var(--text-primary)]"
              >
                {t('common.language')}
              </label>
              <select
                id="profile-locale"
                value={locale}
                onChange={(event) => { setLocale(event.target.value); }}
                className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-raised)] px-3 text-sm text-[var(--text-primary)]"
              >
                {SUPPORTED_LOCALES.map((code) => (
                  <option key={code} value={code} lang={code}>
                    {LOCALE_LABELS[code] ?? code}
                  </option>
                ))}
              </select>
            </div>

            <Field
              label={t('account.timezone')}
              value={timezone}
              onChange={(event) => { setTimezone(event.target.value); }}
              dir="ltr"
              hint={t('account.timezoneHint')}
              error={fieldErrors?.['timezone']?.[0]}
            />

            <Field
              label={t('account.phone')}
              type="tel"
              value={phone}
              onChange={(event) => { setPhone(event.target.value); }}
              autoComplete="tel"
              error={fieldErrors?.['phone']?.[0]}
            />

            <div>
              <Button type="submit" loading={update.isPending}>
                {t('common.save')}
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </>
  )
}
