import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { useDomainAuthorisationCode, useSetDomainTransferLock } from '@/lib/queries'
import type { Domain } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { NotManageableNote } from './NotManageableNote'

/**
 * The two controls somebody needs in order to leave.
 *
 * They belong together and they belong on the customer's own screen: a
 * platform that makes leaving a support ticket is a platform that holds names
 * hostage. The lock is a toggle, and the code is minted on request.
 *
 * The code is shown once, when it was asked for, and is not fetched on load or
 * kept anywhere: it is the credential that moves ownership of a name, so it is
 * on screen only because a person just pressed a button, and it is never
 * logged.
 */
export function DomainLeavingPanel({ domain }: { domain: Domain }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const lock = useSetDomainTransferLock(domain.id)
  const authCode = useDomainAuthorisationCode(domain.id)

  const failure = describeError(lock.error) ?? describeError(authCode.error)

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-sm text-[var(--text-primary)]">
          {domain.transfer_locked === true ? t('domains.lockedOn') : t('domains.lockedOff')}
        </p>

        <Button
          size="sm"
          variant="secondary"
          disabled={!domain.is_manageable}
          loading={lock.isPending}
          onClick={() => { lock.mutate({ locked: domain.transfer_locked !== true }); }}
        >
          {domain.transfer_locked === true ? t('domains.unlock') : t('domains.lock')}
        </Button>

        <Button
          size="sm"
          variant="secondary"
          disabled={!domain.is_manageable}
          loading={authCode.isPending}
          onClick={() => { authCode.mutate(); }}
        >
          {t('domains.getAuthCode')}
        </Button>
      </div>

      <p className="text-sm text-[var(--text-muted)]">{t('domains.leavingExplainer')}</p>

      <NotManageableNote domain={domain} />

      {authCode.data === undefined ? null : (
        <div>
          <Alert tone="info">
            <span className="technical" dir="ltr">
              {authCode.data.data.authorisation_code}
            </span>
          </Alert>
          <p className="mt-1 text-sm text-[var(--text-muted)]">{t('domains.authCodeHint')}</p>
        </div>
      )}

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}
    </div>
  )
}
