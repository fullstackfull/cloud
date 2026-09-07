import { useTranslation } from 'react-i18next'

import { isSupportedLocale, type Locale } from '@/i18n'

/** The active locale, narrowed to one the formatters know about. */
export function useActiveLocale(): Locale {
  const { i18n } = useTranslation()
  const base = i18n.language.split('-')[0] ?? 'en'
  return isSupportedLocale(base) ? base : 'en'
}
