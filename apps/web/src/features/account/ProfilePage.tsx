import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { SelectField } from '@/components/SelectField'
import { PageHeader } from '@/components/PageHeader'
import { useCurrentUser } from '@/features/auth/useAuth'
import { NotificationPreferencesSection } from '@/features/notifications/NotificationPreferencesSection'
import { SUPPORTED_LOCALES, changeLocale, isSupportedLocale } from '@/i18n'
import { canFormatIn, timeZones } from '@/lib/timezones'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { useUpdateProfile } from './useProfile'

/*
 * W5.3. Every field the portal shows about a person or their account,
 * classified against what the server actually accepts.
 *
 * Written here rather than only in the wave report because a report drifts
 * and a file next to the form does not, and because the interesting half of
 * the classification is about what this form deliberately does *not* offer.
 * Each line was read off a route and a validator, not off this screen.
 *
 * ## The person (`GET`/`PATCH /api/v1/me`)
 *
 * EDITABLE — the four keys `ProfileController::update` validates, and the
 * four controls below:
 *   name, locale, timezone, phone (nullable: an empty field is a null)
 *
 * SUPPORT_ONLY:
 *   email             Not in the validator, by an explicit comment on the
 *                     server: changing an address needs re-verification
 *                     through an audited flow, and no such flow is exposed to
 *                     a customer — so the change is support's, which is what
 *                     the hint under the field says. Rendered read-only and
 *                     disabled rather than omitted: an address that is not on
 *                     the page reads as one the platform does not have, and
 *                     it is where every invoice and every notice goes.
 *
 * READ_ONLY:
 *   id, permissions, last_login_at, created_at
 *
 * WORKFLOW_CONTROLLED — real customer-facing changes, each with its own
 * endpoints and its own screen, none of them a field on this form:
 *   password          `PUT me/password`, requiring the current one
 *   two_factor_enabled  enable → confirm → disable, on the security page
 *   email_verified    a signed link, or `POST email/verify/resend`
 *   notification preferences  `PUT me/notification-preferences`, the section
 *                     at the foot of this page: switches that each *are* the
 *                     submit, which is why they are switches and not
 *                     checkboxes in a form
 *
 * ## The account (`customers[]` on the same document)
 *
 * WORKFLOW_CONTROLLED:
 *   country, currency  `account/country-currency-changes`. A request, not a
 *                     setting: the platform analyses what the change would
 *                     touch, an operator decides, and it applies only once
 *                     nothing still priced in the old currency is live.
 *                     `CountryCurrencySection`, on the dashboard, renders
 *                     that workflow — request, re-check, withdraw — and
 *                     offers no direct write. Turning either into an ordinary input would be
 *                     repricing a live catalogue from a form.
 *
 * SUPPORT_ONLY:
 *   display_name, legal_name  set once by registration; the names that go on
 *                     an invoice. No customer or admin endpoint writes them.
 *   status            an operator's, through `PUT admin customers/{id}/status`
 *   type              set by registration; nothing changes it afterwards
 *
 * DERIVED (never stored, so never writable):
 *   can_purchase      computed from verification and account status
 *
 * The server refuses each of these by allow-list rather than trusting the
 * portal to omit them; that refusal is asserted from the outside in
 * `CustomerWritesResistOverpostingTest`, and the absence of a control for
 * them is asserted in `which-fields-a-customer-can-change.test.tsx`.
 */

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

  /*
   * Recomputed only when the stored zone changes: `supportedValuesOf` returns
   * several hundred strings and there is no reason to build that array on
   * every keystroke in the name field.
   */
  const zones = useMemo(() => timeZones(timezone), [timezone])
  const usable = timezone === '' || canFormatIn(timezone)

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

      <div className="flex max-w-xl flex-col gap-6">
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

            <SelectField
              label={t('common.language')}
              value={locale}
              onChange={(event) => { setLocale(event.target.value); }}
            >
              {/*
                Children rather than `options`, because each option carries its
                own `lang`: "العربية" inside an English page has to be marked
                as Arabic or a screen reader pronounces it with English rules.
              */}
              {SUPPORTED_LOCALES.map((code) => (
                <option key={code} value={code} lang={code}>
                  {LOCALE_LABELS[code] ?? code}
                </option>
              ))}
            </SelectField>

            {/*
              Chosen, not typed. The customer used to have to know that the
              string is `Asia/Kuwait` and not "Kuwait", "GMT+3" or "Arabia
              Standard Time"; three of those four were refused by a validator
              that could not explain itself.

              The list is the browser's own IANA database — the same one that
              formats every date the customer then reads — so a zone picked
              here is by construction a zone the formatter can use.
            */}
            <SelectField
              label={t('account.timezone')}
              hint={
                usable
                  ? t('account.timezoneHint')
                  : t('account.timezoneUnknown', { zone: timezone })
              }
              dir="ltr"
              className="technical"
              value={timezone}
              onChange={(event) => { setTimezone(event.target.value); }}
              error={fieldErrors?.['timezone']?.[0]}
              options={zones.map((zone) => ({ value: zone, label: zone }))}
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

        {/*
          * On the profile rather than the security page: what a person wants
          * emailed is a preference, and the security page is for the controls
          * that protect the account.
          */}
        <NotificationPreferencesSection />
      </div>
    </>
  )
}
