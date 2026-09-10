/**
 * A country's name in the reader's own language, from the browser's own data.
 *
 * The server sends ISO-3166-1 alpha-2 codes and no names, deliberately: CLDR
 * already holds every country's name in every locale this portal speaks and in
 * the ones it does not speak yet, and a half-translated table of 249 names
 * shipped in the bundle would be worse than none.
 *
 * `Intl.DisplayNames` is in every browser this portal supports. Where it is
 * missing — an old engine, a test environment with a trimmed ICU — the code
 * itself is shown, which is still a country a customer can recognise.
 */
export function countryName(code: string, locale: string): string {
  try {
    const names = new Intl.DisplayNames([locale], { type: 'region' })

    return names.of(code) ?? code
  } catch {
    return code
  }
}

/**
 * Sorted the way a reader of that language expects, which is not code order:
 * an Arabic list sorted by "AE, AF, AL" is a list nobody can scan.
 */
export function sortByCountryName<T extends { code: string }>(countries: T[], locale: string): T[] {
  const collator = new Intl.Collator(locale)

  return [...countries].sort((left, right) =>
    collator.compare(countryName(left.code, locale), countryName(right.code, locale)),
  )
}
