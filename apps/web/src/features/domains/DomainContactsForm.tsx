import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { useDomainContacts, useUpdateDomainContacts } from '@/lib/queries'
import type { Domain } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The registrant on record, read back and correctable.
 *
 * The update endpoint has existed since the domains module was built with no
 * screen behind it, which meant a customer whose address changed had to open a
 * ticket — and a registrant record that is wrong is what loses a name at a
 * registry that verifies contacts.
 *
 * The current values are fetched so a correction does not have to be retyped
 * from memory. That read is its own request behind its own permission, is not
 * cached across a visit, and is deliberately not part of the domain list: a
 * table of names has no business carrying a home address for each one.
 *
 * This is the customer's own personal data. It is sent to the registrar and
 * nowhere else, and nothing here is logged — the platform's logging rules name
 * registrant details explicitly.
 */
export function DomainContactsForm({ domain }: { domain: Domain }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const { data: contact, isPending, error } = useDomainContacts(domain.name)
  const update = useUpdateDomainContacts(domain.name)

  const [fields, setFields] = useState({
    name: '',
    organisation: '',
    email: '',
    phone: '',
    address_line_one: '',
    address_line_two: '',
    city: '',
    region: '',
    postal_code: '',
    country: '',
  })

  /*
   * Filled from the record once it arrives, and not overwritten afterwards:
   * a refetch that landed while somebody was typing would throw away the
   * correction they were making.
   */
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    if (loaded || contact === undefined) return

    setFields({
      name: contact.name,
      organisation: contact.organisation ?? '',
      email: contact.email,
      phone: contact.phone,
      address_line_one: contact.address_line_one,
      address_line_two: contact.address_line_two ?? '',
      city: contact.city,
      region: contact.region ?? '',
      postal_code: contact.postal_code ?? '',
      country: contact.country,
    })
    setLoaded(true)
  }, [contact, loaded])

  const failure = describeError(update.error)

  const set = (key: keyof typeof fields) => (event: { target: { value: string } }) => {
    setFields((current) => ({ ...current, [key]: event.target.value }))
  }

  if (isPending) return <Loading />

  return (
    <>
      <LoadFailure error={error} />

      <form
        className="grid gap-3 sm:grid-cols-2"
        onSubmit={(event) => {
          event.preventDefault()

          update.mutate({
            name: fields.name,
            // The optional halves are sent as null when empty rather than as
            // an empty string: a blank organisation is the absence of one.
            organisation: fields.organisation === '' ? null : fields.organisation,
            email: fields.email,
            phone: fields.phone,
            address_line_one: fields.address_line_one,
            address_line_two: fields.address_line_two === '' ? null : fields.address_line_two,
            city: fields.city,
            region: fields.region === '' ? null : fields.region,
            postal_code: fields.postal_code === '' ? null : fields.postal_code,
            country: fields.country,
          })
        }}
      >
        <Field
          label={t('domains.registrantName')}
          value={fields.name}
          onChange={set('name')}
          error={failure?.fields?.['registrant.name']?.[0]}
          required
        />
        <Field
          label={t('domains.registrantOrganisation')}
          value={fields.organisation}
          onChange={set('organisation')}
          error={failure?.fields?.['registrant.organisation']?.[0]}
        />
        <Field
          label={t('domains.registrantEmail')}
          type="email"
          value={fields.email}
          onChange={set('email')}
          error={failure?.fields?.['registrant.email']?.[0]}
          required
        />
        <Field
          label={t('domains.registrantPhone')}
          dir="ltr"
          value={fields.phone}
          onChange={set('phone')}
          error={failure?.fields?.['registrant.phone']?.[0]}
          required
        />
        <Field
          label={t('domains.registrantAddress')}
          value={fields.address_line_one}
          onChange={set('address_line_one')}
          error={failure?.fields?.['registrant.address_line_one']?.[0]}
          required
        />
        <Field
          label={t('domains.registrantAddressTwo')}
          value={fields.address_line_two}
          onChange={set('address_line_two')}
        />
        <Field
          label={t('domains.registrantCity')}
          value={fields.city}
          onChange={set('city')}
          error={failure?.fields?.['registrant.city']?.[0]}
          required
        />
        <Field
          label={t('domains.registrantRegion')}
          value={fields.region}
          onChange={set('region')}
        />
        <Field
          label={t('domains.registrantPostalCode')}
          dir="ltr"
          value={fields.postal_code}
          onChange={set('postal_code')}
        />
        <Field
          label={t('domains.registrantCountry')}
          dir="ltr"
          placeholder="KW"
          value={fields.country}
          onChange={set('country')}
          error={failure?.fields?.['registrant.country']?.[0]}
          required
        />

        <div className="flex gap-3 sm:col-span-2">
          <Button
            type="submit"
            disabled={!domain.is_manageable}
            loading={update.isPending}
          >
            {t('common.save')}
          </Button>
        </div>
      </form>

      {update.isSuccess ? (
        <div className="mt-3">
          <Alert tone="info">{t('domains.contactsSaved')}</Alert>
        </div>
      ) : null}

      {failure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      )}

      <p className="mt-3 text-xs text-[var(--text-muted)]">{t('domains.contactsPrivacy')}</p>
    </>
  )
}
