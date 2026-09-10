import { useTranslation } from 'react-i18next'

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
 */
export function Loading({ size = 'region', className }: LoadingProps) {
  const { t } = useTranslation()

  return (
    <p
      role="status"
      className={cn(
        'text-center text-sm text-[var(--text-muted)]',
        size === 'screen' ? 'flex min-h-dvh items-center justify-center' : 'py-8',
        className,
      )}
    >
      {t('common.loading')}
    </p>
  )
}
