import { useTranslation } from 'react-i18next'

import { Spinner } from '@/components/Spinner'
import { cn } from '@/lib/cn'

interface LoadingProps {
  /**
   * How much room the state takes. `region` is a list or a card body that is
   * still coming; `screen` is the whole viewport, used by the route guards
   * before anything else can be drawn.
   */
  size?: 'region' | 'screen'
  className?: string
}

/**
 * The one way a screen says it is still waiting for data.
 *
 * `role="status"` with the default polite live region: assistive technology
 * announces it once, when it appears, and does not interrupt. The audit found
 * fifty-one inline "Loading…" paragraphs with no role at all and one
 * hard-coded English "Loading" in the route guards; none of them was
 * announced, and one of them was not translated. This component is what they
 * all render now, so the sentence comes from the catalogue in the active
 * language and the semantics cannot drift per screen.
 *
 * Deliberately not used for a button that is submitting — that is the
 * button's own `loading` state, which sets `aria-busy` on the control the
 * person pressed rather than announcing a second thing.
 *
 * ## Why there is a turning mark and not only a sentence
 *
 * Until W5.8 this component and `EmptyState` rendered the same box with the
 * same four classes, differing only in their words. So "your servers are on
 * their way" and "you have no servers" were the same picture, and the moment
 * the answer arrived was invisible: a customer who glanced away could not tell
 * whether the screen had finished. A read that failed was already distinct — a
 * red-toned alert — but two of the three states were not, and §25 asks for
 * three.
 *
 * The mark is the smallest change that separates them. It is not a skeleton
 * layout: guessing the shape of the table that is coming would be a redesign,
 * and W5.8 is not one. Reduced motion stops it turning and leaves it drawn,
 * which is still a different picture from an empty state's bare sentence.
 */
export function Loading({ size = 'region', className }: LoadingProps) {
  const { t } = useTranslation()

  return (
    <p
      role="status"
      className={cn(
        'flex items-center justify-center gap-2 text-sm text-[var(--text-muted)]',
        size === 'screen' ? 'min-h-dvh' : 'py-8',
        className,
      )}
    >
      <Spinner />
      {t('common.loading')}
    </p>
  )
}
