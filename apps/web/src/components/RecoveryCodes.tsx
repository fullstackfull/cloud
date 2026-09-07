import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'

/**
 * Recovery codes, shown exactly once.
 *
 * They are rendered as selectable text rather than behind a download button:
 * a blob download is inert inside a sandboxed frame, and a code the customer
 * cannot copy is a code they do not have.
 */
export function RecoveryCodes({ codes }: { codes: string[] }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-col gap-3">
      <Alert tone="warning" title={t('security.recoveryCodesTitle')}>
        {t('security.recoveryCodesWarning')}
      </Alert>

      <ul
        dir="ltr"
        className="technical grid grid-cols-2 gap-2 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-3 text-sm"
      >
        {codes.map((code) => (
          <li key={code} className="select-all tracking-wider">
            {code}
          </li>
        ))}
      </ul>
    </div>
  )
}
