import { useTranslation } from 'react-i18next'

/** The id the skip link lands on, and the id `<main>` must carry. */
export const MAIN_CONTENT_ID = 'main-content'

/**
 * The first thing in the tab order, and invisible until it is reached.
 *
 * A portal with a sidebar of twenty-one destinations puts twenty-one stops
 * between the top of the page and the page itself. Somebody navigating by
 * keyboard pays that on every single navigation; somebody using a screen
 * reader hears the whole navigation read out before the thing they came for.
 *
 * Positioned rather than hidden. `display: none` and `visibility: hidden` both
 * remove an element from the tab order, which is the one thing this must stay
 * in — so it sits off the top of the viewport and comes back on focus. It is
 * placed with `start-4` rather than `left-4` so that it appears on the correct
 * side in Arabic, and above the sticky header's z-index so that it is not
 * revealed underneath it.
 */
export function SkipLink() {
  const { t } = useTranslation()

  return (
    <a
      href={`#${MAIN_CONTENT_ID}`}
      className={[
        'absolute start-4 z-50 -translate-y-full rounded-lg px-4 py-2',
        'bg-[var(--surface-raised)] text-sm font-medium text-[var(--text-primary)]',
        'border border-[var(--border-strong)] shadow-sm',
        'focus:translate-y-4 focus-visible:translate-y-4',
        'transition-transform motion-reduce:transition-none',
      ].join(' ')}
    >
      {t('nav.skipToContent')}
    </a>
  )
}
