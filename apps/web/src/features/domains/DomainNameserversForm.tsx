import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { TextareaField } from '@/components/TextareaField'
import { useSetDomainNameservers } from '@/lib/queries'
import type { Domain } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { NotManageableNote } from './NotManageableNote'

/**
 * Where this name points.
 *
 * The same mutation the domains list used to carry, moved to the name's own
 * page. One field, one save, and the hosts are sent as typed — the platform
 * validates them and is the one that decides which are acceptable.
 */
export function DomainNameserversForm({ domain }: { domain: Domain }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const nameservers = useSetDomainNameservers(domain.id)

  const [hosts, setHosts] = useState(domain.nameservers.join('\n'))

  const failure = describeError(nameservers.error)

  return (
    <>
      <form
        className="flex flex-col gap-2"
        onSubmit={(event) => {
          event.preventDefault()

          nameservers.mutate({
            nameservers: hosts
              .split(/[\s,]+/)
              .map((host) => host.trim())
              .filter((host) => host !== ''),
          })
        }}
      >
        {/* Host names, so left to right on an Arabic page too. */}
        <TextareaField
          label={t('domains.nameservers')}
          hint={t('domains.nameserversHint')}
          dir="ltr"
          rows={4}
          className="technical"
          value={hosts}
          onChange={(event) => { setHosts(event.target.value); }}
        />

        <div>
          <Button type="submit" disabled={!domain.is_manageable} loading={nameservers.isPending}>
            {t('common.save')}
          </Button>
        </div>

        <NotManageableNote domain={domain} />
      </form>

      {nameservers.isSuccess ? (
        <div className="mt-3">
          <Alert tone="info">{t('domains.nameserversSaved')}</Alert>
        </div>
      ) : null}

      {failure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      )}
    </>
  )
}
