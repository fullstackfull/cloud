import { useTranslation } from 'react-i18next'

import { readDevice } from '@/lib/deviceLabel'

/**
 * "Chrome on macOS", or as much of it as the user-agent string actually claims.
 *
 * The sentence is composed here rather than inside the parser because it is a
 * sentence: Arabic joins the two halves with a different word in a different
 * place, and a parser that returned "Chrome on macOS" would have made the
 * Arabic page impossible to write. The parser returns two ids; this turns them
 * into language.
 *
 * Shared by the sessions table and the sign-in history because they describe
 * the same fact — a sign-in and the session it opened — and a customer
 * comparing the two rows must not be reading two different vocabularies.
 *
 * Nothing here is inferred. No device model, no version, no city. A user agent
 * is a claim the client makes, routinely frozen and reduced by the browsers
 * themselves, and a row that read "iPhone 14 Pro in Kuwait City" would be
 * inventing two facts to dress up a third — on the one screen whose whole
 * purpose is recognising the entry that is not yours.
 */
export function useDeviceName(): (userAgent: string | null | undefined) => string {
  const { t } = useTranslation()

  return (userAgent) => {
    const { browser, platform } = readDevice(userAgent)

    if (browser === null && platform === null) return t('security.unknownDevice')

    return t('security.deviceOn', {
      browser: browser === null ? t('security.unknownBrowser') : t(`security.browsers.${browser}`),
      platform:
        platform === null ? t('security.unknownPlatform') : t(`security.platforms.${platform}`),
    })
  }
}
