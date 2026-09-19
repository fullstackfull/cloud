import { expect, type Locator } from '@playwright/test'

/**
 * Whether a piece of customer text is Arabic prose.
 *
 * Arabic letters must be present, and no run of English words may be: three
 * or more Latin words in a row is a sentence, and a sentence is a translation
 * that did not happen. Technical tokens are allowed on their own — `e2e-web-01`,
 * `INV-E2E-0001`, `example.com`, `IPv4`, `API`, `KWD 12.500` — because they are
 * the same in every language and forcing them into Arabic would make them
 * wrong. The test therefore distinguishes a Latin *identifier* from Latin
 * *prose* by shape: an identifier has no spaces between plain words.
 */
export function isArabicProse(text: string): boolean {
  const hasArabic = /\p{Script=Arabic}/u.test(text)
  const latinSentence = /\b[A-Za-z]{2,}\s+[A-Za-z]{2,}\s+[A-Za-z]{2,}\b/.test(
    // Strip what is legitimately Latin before looking for prose.
    text
      .replace(/[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, ' ') // domains, hostnames
      .replace(/\b[A-Z]{2,}[-A-Z0-9]*\b/g, ' ') // INV-E2E-0001, KWD, API, DNS, IPv4
      .replace(/\b\d+(\.\d+)?\b/g, ' '),
  )

  return hasArabic && !latinSentence
}

export async function expectArabicProse(locator: Locator): Promise<void> {
  const text = (await locator.textContent()) ?? ''
  expect(isArabicProse(text), `Not Arabic prose: "${text}"`).toBe(true)
}
