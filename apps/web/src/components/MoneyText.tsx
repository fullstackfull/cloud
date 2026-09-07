import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatMoney } from '@/lib/format'
import type { Money } from '@/lib/types'

/**
 * An amount, always in the currency the server sent it in.
 *
 * Wrapped in a component rather than called inline so that no screen can quietly
 * render an amount without its currency, and so the direction is right: an
 * amount is Latin-numeral and left-to-right even on an Arabic page, because a
 * mirrored total is a total somebody will read wrong.
 */
export function MoneyText({ value }: { value: Money }) {
  const locale = useActiveLocale()

  return (
    <span dir="ltr" className="tabular-nums whitespace-nowrap">
      {formatMoney(value, locale)}
    </span>
  )
}
