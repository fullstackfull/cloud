import { Outlet } from 'react-router'
import { useTranslation } from 'react-i18next'

import { LocaleSwitcher } from '@/components/LocaleSwitcher'

/** The signed-out shell: one centred card, and a way to change language. */
export function PublicLayout() {
  const { t } = useTranslation()

  return (
    <div className="flex min-h-dvh flex-col bg-[var(--surface)]">
      <header className="flex items-center justify-between px-4 py-4 sm:px-8">
        <span className="text-sm font-semibold text-[var(--text-primary)]">{t('common.appName')}</span>
        <LocaleSwitcher />
      </header>

      <main className="flex flex-1 items-center justify-center px-4 py-8">
        <div className="w-full max-w-sm">
          <Outlet />
        </div>
      </main>
    </div>
  )
}
