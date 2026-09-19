import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatMinorUnits } from '@/lib/format'
import { useQuoteDomain, useTransferDomainIn } from '@/lib/queries'
import type { DomainOperation } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Bringing a name in from another registrar.
 *
 * On the index rather than on a domain's page, because the platform does not
 * hold the name yet — there is nothing to open. The endpoint has existed since
 * the domains module was built and had no screen at all: transferring in meant
 * opening a ticket.
 *
 * Two properties of this form are load-bearing.
 *
 * **The authorisation code travels once.** It is typed, sent, and cleared. It
 * is never rendered back, never stored in this component after the request,
 * and never logged — it is the credential that moves ownership of a name, and
 * a screen that displays it back "for confirmation" leaves it in the page for
 * anything that can read the DOM.
 *
 * **Initiating is not completing.** A transfer takes days and the losing
 * registrar and the registry both get a say. So the result says the transfer
 * was requested and links to the invoice; it does not say the name is yours,
 * and nothing here retries an outcome the platform did not hear the end of.
 */
export function DomainTransferInForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const quote = useQuoteDomain()
  const transfer = useTransferDomainIn()

  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [result, setResult] = useState<DomainOperation | null>(null)

  const failure = describeError(quote.error) ?? describeError(transfer.error)

  return (
    <Card title={t('domains.transferIn.title')} description={t('domains.transferIn.body')}>
      {result === null ? (
        <form
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault()

            quote.mutate(
              { name: name.trim().toLowerCase(), operation: 'transfer' },
              {
                onSuccess: (quoted) => {
                  transfer.mutate(
                    { quote_id: quoted.data.id, authorisation_code: code },
                    {
                      onSuccess: (operation) => {
                        // Cleared the moment it has been sent. Nothing keeps
                        // it and nothing shows it again.
                        setCode('')
                        setResult(operation.data)
                      },
                    },
                  )
                },
              },
            )
          }}
        >
          <Field
            label={t('domains.transferIn.name')}
            dir="ltr"
            placeholder="example.com"
            value={name}
            onChange={(event) => { setName(event.target.value); }}
            required
          />

          <Field
            label={t('domains.transferIn.code')}
            /*
             * A password field: it is a credential, so it is not left legible
             * on a shared screen and browsers do not offer to remember it in
             * a plain-text history.
             */
            type="password"
            autoComplete="off"
            value={code}
            onChange={(event) => { setCode(event.target.value); }}
            hint={t('domains.transferIn.codeHint')}
            error={failure?.fields?.['authorisation_code']?.[0]}
            required
          />

          <div className="flex gap-3 sm:col-span-2">
            <Button type="submit" loading={quote.isPending || transfer.isPending}>
              {t('domains.transferIn.submit')}
            </Button>
            <Button type="button" variant="ghost" onClick={onDone}>
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      ) : (
        <div className="flex flex-col gap-3">
          <Alert tone={result.needs_attention ? 'warning' : 'info'}>
            {result.needs_attention
              ? t('domains.transferIn.needsReview')
              : t('domains.transferIn.requested', { domain: result.name })}
          </Alert>

          <p className="text-sm text-[var(--text-secondary)]">
            {t('domains.transferIn.price', {
              price: formatMinorUnits(result.price_minor, result.currency, locale),
            })}
          </p>

          {result.invoice_id === null ? null : (
            <p className="text-sm">
              <Link to={`/invoices/${result.invoice_id}`} className="underline">
                {t('domains.transferIn.openInvoice')}
              </Link>
            </p>
          )}

          <p className="text-sm text-[var(--text-muted)]">{t('domains.transferIn.waitExplainer')}</p>

          <div>
            <Button variant="ghost" onClick={onDone}>
              {t('common.close')}
            </Button>
          </div>
        </div>
      )}

      {failure === null || failure.fields !== null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      )}
    </Card>
  )
}
