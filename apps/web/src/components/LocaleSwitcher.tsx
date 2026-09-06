import { useTranslation } from 'react-i18next'

import { SUPPORTED_LOCALES, changeLocale, isSupportedLocale } from '@/i18n'
import { cn } from '@/lib/cn'

const LABELS: Record<string, string> = {
  en: 'English',
  ar: 'العربية',
}

/**
 * Each language is labelled in its own script, never translated: someone who
 * has landed on a page in a language they cannot read needs to recognise their
 * own language to get out of it.
 */
export function LocaleSwitcher({ className }: { className?: string }) {
  const { i18n, t } = useTranslation()
  const active = i18n.language.split('-')[0] ?? 'en'

  return (
    <div className={cn('flex items-center gap-1', className)} role="group" aria-label={t('common.language')}>
      {SUPPORTED_LOCALES.map((locale) => (
        <button
          key={locale}
          type="button"
          lang={locale}
          aria-pressed={active === locale}
          onClick={() => {
            if (isSupportedLocale(locale)) void changeLocale(locale)
          }}
          className={cn(
            'rounded-md px-2 py-1 text-xs font-medium transition-colors',
            active === locale
              ? 'bg-[var(--surface-sunken)] text-[var(--text-primary)]'
              : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]',
          )}
        >
          {LABELS[locale] ?? locale}
        </button>
      ))}
    </div>
  )
}
