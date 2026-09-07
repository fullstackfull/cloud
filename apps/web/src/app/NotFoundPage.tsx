import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

export function NotFoundPage() {
  const { t } = useTranslation()

  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-4 bg-[var(--surface)] px-4 text-center">
      <h1 className="text-2xl font-semibold text-[var(--text-primary)]">{t('errors.pageNotFound')}</h1>
      <p className="text-sm text-[var(--text-secondary)]">{t('errors.pageNotFoundBody')}</p>
      <Link to="/" className="text-sm font-medium text-brand-600 hover:underline">
        {t('nav.dashboard')}
      </Link>
    </main>
  )
}
