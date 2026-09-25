import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { CheckboxField } from '@/components/CheckboxField'
import { Field } from '@/components/Field'
import { RadioGroup } from '@/components/RadioGroup'
import { SelectField } from '@/components/SelectField'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { api } from '@/lib/api'
import { countryName, sortByCountryName } from '@/lib/countryNames'
import { useRegistrationOptions } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { useMutation } from '@tanstack/react-query'

import type { RegistrationLegalDocuments } from '@/lib/types'
import type { TFunction } from 'i18next'
import type { ReactNode } from 'react'

/**
 * Links to the documents a customer is being asked to accept, or nothing.
 *
 * The checkbox sentence names a terms of service and an acceptable use
 * policy, and for a long time named them with no way to read either. The URLs
 * are the operator's to publish — the documents are written and reviewed by
 * people, not generated here — so they arrive with the rest of what a
 * registration form is allowed to offer and the screen renders whichever of
 * the two exists.
 *
 * Returning undefined for the unpublished case is deliberate, and it is not
 * the same as rendering an empty line: `CheckboxField` omits the hint
 * paragraph entirely, so an unpublished document leaves no gap under the
 * sentence and no link that goes nowhere. That state is a launch prerequisite
 * recorded as one, rather than a placeholder page pretending to be terms.
 *
 * Every document here is published — the server omits the rest, so there is
 * no null to filter and no half-published case to render. Each link names the
 * revision beside it, because that is what the checkbox commits the customer
 * to and it is what the acceptance records.
 */
function legalDocuments(
  legal: RegistrationLegalDocuments | undefined,
  t: TFunction,
): ReactNode {
  const labels: Record<string, string> = {
    terms: t('auth.termsDocument'),
    aup: t('auth.aupDocument'),
  }

  const documents = legal?.documents ?? []

  if (documents.length === 0) {
    return undefined
  }

  return (
    <span className="flex flex-wrap gap-x-3 gap-y-1">
      {documents.map((document) => (
        <a
          key={document.type}
          href={document.url}
          target="_blank"
          // noopener because the document is on somebody else's origin, and a
          // tab opened with a handle on this one can navigate it away from a
          // half-filled registration form.
          rel="noreferrer noopener"
          className="font-medium text-brand-600 hover:underline"
        >
          {labels[document.type] ?? document.type}{' '}
          <span className="font-normal text-[var(--text-secondary)]">
            ({t('auth.documentVersion', { version: document.version })})
          </span>
        </a>
      ))}
    </span>
  )
}

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

  /*
   * Whether the server will take a registration, read as three answers and
   * not two, because the unknown one points in opposite directions for the
   * two things on this screen that read it:
   *
   *  - the "not open yet" warning needs a `false` — saying the platform is
   *    closed is a claim this screen has no evidence for when it could not
   *    ask, or when the answer did not say;
   *  - the Create-account button needs a `true` — not knowing is not
   *    permission.
   *
   * One boolean for both was the defect: `=== false` on an answer that had
   * not arrived left the button live under a form with no countries in it.
   *
   * `legal` is optional-chained although its type says it is always present.
   * The type is the contract between the portal and the API, and the two
   * deploy separately; the `?.` describes the wire. Without it a 200 that
   * omits `legal` throws in render and blanks the whole registration route.
   */
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- the wire, not the type: see above
  const permitted: unknown = options.data?.legal?.registration_permitted
  const registrationPermitted = permitted === true
  const registrationClosed = permitted === false
  // An answer arrived and said neither: a portal and an API out of step, not
  // a closed platform, and not something to be silent about.
  const registrationUnconfirmed = options.data !== undefined && !registrationPermitted && !registrationClosed
  // A failed read with nothing earlier to fall back on. A refetch that fails
  // after a good answer keeps that answer, and the form stays usable on it.
  const optionsFailure = options.data === undefined ? describeError(options.error) : null

  const askAgain = (
    <Button
      // A <button> with no type inside a <form> is a submit button: pressing
      // it would refetch the options *and* post the half-filled registration,
      // and it would become the form's default button, so Enter in any field
      // would post it too.
      type="button"
      variant="secondary"
      size="sm"
      loading={options.isFetching}
      onClick={() => {
        void options.refetch()
      }}
    >
      {t('common.retry')}
    </Button>
  )

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

      {/*
        Said before the form is filled in, not after it is submitted.

        The server refuses the registration either way — that is the
        enforcement, and it does not depend on this — but a visitor who has
        typed a name, an address and a password twice deserves to have been
        told first. Three things can stop this form, and each says so:

         - the options could not be read at all, so there are no countries,
           no currencies and no documents to agree to — an error, because an
           empty select is exactly what a first render looks like, and
           without this nothing told a failed page from a slow one;
         - they were read and did not say whether registration is open — an
           error too, because the countries, currencies and document links
           are all on screen and the greyed button would otherwise be the
           only thing different from a page that works;
         - the server said registration is not open — a warning, and only
           then, so a slow or failed request never tells somebody who can
           register that they cannot.

        A request still on its way says nothing: it is a moment's wait, not a
        failure, and the button waits with it.
      */}
      {optionsFailure !== null ? (
        <Alert tone="error" title={t('auth.registrationOptionsFailed')} requestId={optionsFailure.requestId}>
          <span className="flex flex-col items-start gap-2">
            <span>{optionsFailure.message}</span>
            {askAgain}
          </span>
        </Alert>
      ) : registrationUnconfirmed ? (
        <Alert tone="error">
          <span className="flex flex-col items-start gap-2">
            <span>{t('auth.registrationUnconfirmed')}</span>
            {askAgain}
          </span>
        </Alert>
      ) : registrationClosed ? (
        <Alert tone="warning">{t('auth.registrationUnavailable')}</Alert>
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

      <RadioGroup
        label={t('auth.accountType')}
        name="account_type"
        value={accountType}
        onChange={(picked) => { setAccountType(picked as 'individual' | 'organization'); }}
        error={fieldErrors?.['account_type']?.[0]}
        options={(['individual', 'organization'] as const).map((option) => ({
          value: option,
          label: t(`auth.accountTypes.${option}`),
        }))}
      />

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

      <CheckboxField
        label={t('auth.acceptTerms')}
        hint={legalDocuments(options.data?.legal, t)}
        checked={acceptsTerms}
        onChange={(event) => { setAcceptsTerms(event.target.checked); }}
        error={fieldErrors?.['accepts_terms']?.[0]}
        required
      />

      {/*
        Live on the server's literal `true` and nothing else. Pending, failed,
        closed, or an answer that does not say: each leaves it disabled, and
        all but the first say why above. Disabled also stops Enter: implicit
        submission presses the form's default button, which is this one.
      */}
      <Button
        type="submit"
        loading={register.isPending}
        disabled={!registrationPermitted}
        className="w-full"
      >
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
