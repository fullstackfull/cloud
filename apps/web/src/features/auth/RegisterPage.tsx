import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Field } from '@/components/Field'
import { SelectField } from '@/components/SelectField'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { api } from '@/lib/api'
import { countryName, sortByCountryName } from '@/lib/countryNames'
import { useRegistrationOptions } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { useMutation } from '@tanstack/react-query'

interface RegistrationPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  account_type: 'individual' | 'organization'
  company_name?: string
  country: string
  currency: string
  accepts_terms: boolean
}

export function RegisterPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [accountType, setAccountType] = useState<'individual' | 'organization'>('individual')
  const [companyName, setCompanyName] = useState('')
  const [acceptsTerms, setAcceptsTerms] = useState(false)

  /*
   * The country decides the currency, and both come from the server.
   *
   * The portal holds no country-to-currency table of its own: it asks, shows
   * the recommendation before anything is submitted, and lets the customer
   * override it with any currency the platform bills in. Registration used to
   * ask neither question and book every account in the platform's own
   * currency, which a customer discovered on their first invoice.
   */
  const options = useRegistrationOptions()
  const locale = useActiveLocale()

  const [country, setCountry] = useState('')
  const [currency, setCurrency] = useState<string | null>(null)

  const countries = sortByCountryName(options.data?.countries ?? [], locale)
  const chosen = countries.find((row) => row.code === country) ?? null
  const recommended = chosen?.currency ?? null
  const effectiveCurrency = currency ?? recommended ?? ''

  const register = useMutation({
    mutationFn: (payload: RegistrationPayload) => api.post<unknown>('/register', payload),
  })

  const displayed = describeError(register.error)
  const fieldErrors = displayed?.fields ?? null

  if (register.isSuccess) {
    /*
     * Registration deliberately does not sign the customer in: verification
     * comes first, so a mistyped address cannot leave an unreachable account
     * holding a live session.
     */
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold text-[var(--text-primary)]">
          {t('auth.checkYourEmailTitle')}
        </h1>
        <Alert tone="success">{t('auth.checkYourEmailBody', { email })}</Alert>
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
        register.mutate({
          name,
          email,
          password,
          password_confirmation: confirmation,
          account_type: accountType,
          ...(accountType === 'organization' ? { company_name: companyName } : {}),
          country,
          // Whatever the screen is showing, which is the recommendation until
          // the customer changes it. Never blank and never guessed here: the
          // server refuses a currency it does not bill in.
          currency: effectiveCurrency,
          accepts_terms: acceptsTerms,
        })
      }}
    >
      <header>
        <h1 className="text-2xl font-semibold text-[var(--text-primary)]">
          {t('auth.registerTitle')}
        </h1>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">{t('auth.registerSubtitle')}</p>
      </header>

      {displayed !== null ? (
        <Alert tone="error" requestId={displayed.requestId}>
          {displayed.message}
        </Alert>
      ) : null}

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
        value={email}
        onChange={(event) => { setEmail(event.target.value); }}
        autoComplete="email"
        required
        error={fieldErrors?.['email']?.[0]}
      />

      <fieldset className="flex flex-col gap-2">
        <legend className="text-sm font-medium text-[var(--text-primary)]">
          {t('auth.accountType')}
        </legend>
        <div className="flex gap-4 text-sm">
          {(['individual', 'organization'] as const).map((option) => (
            <label key={option} className="flex items-center gap-2 text-[var(--text-secondary)]">
              <input
                type="radio"
                name="account_type"
                value={option}
                checked={accountType === option}
                onChange={() => { setAccountType(option); }}
              />
              {t(`auth.accountTypes.${option}`)}
            </label>
          ))}
        </div>
      </fieldset>

      {accountType === 'organization' ? (
        <Field
          label={t('auth.companyName')}
          value={companyName}
          onChange={(event) => { setCompanyName(event.target.value); }}
          autoComplete="organization"
          required
          error={fieldErrors?.['company_name']?.[0]}
        />
      ) : null}

      {/*
        Asked before the password, because it changes what the customer is
        agreeing to: the currency their invoices will be issued in.
      */}
      <SelectField
        label={t('auth.country')}
        value={country}
        onChange={(event) => {
          setCountry(event.target.value)
          // A new country brings a new recommendation. An override the
          // customer made for a different country would otherwise be carried
          // silently into this one.
          setCurrency(null)
        }}
        required
        options={[
          { value: '', label: t('auth.chooseCountry') },
          ...countries.map((row) => ({ value: row.code, label: countryName(row.code, locale) })),
        ]}
        hint={t('auth.countryHint')}
        error={fieldErrors?.['country']?.[0]}
      />

      <SelectField
        label={t('auth.currency')}
        value={effectiveCurrency}
        onChange={(event) => { setCurrency(event.target.value); }}
        dir="ltr"
        disabled={country === ''}
        options={(options.data?.currencies ?? []).map((code) => ({ value: code, label: code }))}
        hint={
          country === ''
            ? t('auth.currencyAwaitingCountry')
            : chosen?.currency_is_explicit === true
              ? t('auth.currencyRecommended', { currency: recommended ?? '' })
              : t('auth.currencyFallback', { currency: recommended ?? '' })
        }
        error={fieldErrors?.['currency']?.[0]}
      />

      <Field
        label={t('common.password')}
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

      <label className="flex items-start gap-2 text-sm text-[var(--text-secondary)]">
        <input
          type="checkbox"
          checked={acceptsTerms}
          onChange={(event) => { setAcceptsTerms(event.target.checked); }}
          className="mt-0.5"
          required
        />
        <span>{t('auth.acceptTerms')}</span>
      </label>
      {fieldErrors?.['accepts_terms']?.[0] !== undefined ? (
        <p role="alert" className="text-xs text-red-600 dark:text-red-400">
          {fieldErrors['accepts_terms'][0]}
        </p>
      ) : null}

      <Button type="submit" loading={register.isPending} className="w-full">
        {t('common.register')}
      </Button>

      <p className="text-center text-sm text-[var(--text-secondary)]">
        {t('auth.haveAccount')}{' '}
        <Link to="/sign-in" className="font-medium text-brand-600 hover:underline">
          {t('common.signIn')}
        </Link>
      </p>
    </form>
  )
}
