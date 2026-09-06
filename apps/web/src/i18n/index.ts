import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import ar from './locales/ar.json'
import en from './locales/en.json'

export const SUPPORTED_LOCALES = ['en', 'ar'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]

/** Locales written right-to-left. Direction is a property of the locale, not a user setting. */
const RTL_LOCALES = new Set<Locale>(['ar'])

export function directionFor(locale: Locale): 'rtl' | 'ltr' {
  return RTL_LOCALES.has(locale) ? 'rtl' : 'ltr'
}

export function isSupportedLocale(value: string): value is Locale {
  return (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

const STORAGE_KEY = 'lynomia.locale'

function readStoredLocale(): Locale | null {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    return stored !== null && isSupportedLocale(stored) ? stored : null
  } catch {
    // Private browsing and blocked site data both throw here. A missing
    // preference is not an error: fall through to the browser's own languages.
    return null
  }
}

export function detectLocale(): Locale {
  const stored = readStoredLocale()
  if (stored !== null) return stored

  for (const candidate of navigator.languages) {
    const base = candidate.split('-')[0]
    if (base !== undefined && isSupportedLocale(base)) return base
  }

  return 'en'
}

/**
 * Applies a locale to the document.
 *
 * `lang` and `dir` live on <html> so that CSS logical properties, the browser's
 * own text selection and hyphenation, and assistive technology all agree on the
 * language and direction. Mirroring layout in CSS alone would leave screen
 * readers announcing Arabic content as English.
 */
export function applyLocale(locale: Locale): void {
  const root = document.documentElement
  root.lang = locale
  root.dir = directionFor(locale)

  try {
    window.localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // A viewer who blocks site data still gets the locale for this session.
  }
}

export async function changeLocale(locale: Locale): Promise<void> {
  await i18n.changeLanguage(locale)
  applyLocale(locale)
}

void i18n.use(initReactI18next).init({
  resources: {
    en: { translation: en },
    ar: { translation: ar },
  },
  lng: detectLocale(),
  fallbackLng: 'en',
  interpolation: {
    // React escapes on render; escaping again here would double-encode.
    escapeValue: false,
  },
  returnNull: false,
})

export default i18n
