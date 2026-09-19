import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { useHostingSso } from '@/lib/queries'
import type { HostingAccount } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { panelNameKey } from './panelName'

/**
 * The one door into a hosting account's control panel.
 *
 * One component for the list row and the account's own page. The link it mints
 * is a one-time credential, so it is opened and never rendered: text in the
 * page is readable by anything with access to the DOM, and a URL that is
 * navigated to directly ends up in browser history. The server has already
 * checked that it points at the account's own node — that check lives there,
 * because a client is not a place to enforce anything.
 */
export function HostingPanelButton({
  account,
  size = 'sm',
}: {
  account: HostingAccount
  size?: 'sm' | 'md'
}) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const sso = useHostingSso()

  const failure = describeError(sso.error)

  async function openPanel() {
    const opened = await sso.mutateAsync(account.id).catch(() => null)

    if (opened !== null && opened.url !== '') {
      window.open(opened.url, '_blank', 'noopener,noreferrer')
    }
  }

  return (
    <div className="flex flex-col items-end gap-2">
      <Button
        size={size}
        variant="secondary"
        loading={sso.isPending}
        onClick={() => void openPanel()}
      >
        {t('hosting.openNamedPanel', { panel: t(panelNameKey(account.panel_type)) })}
      </Button>

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}
    </div>
  )
}
