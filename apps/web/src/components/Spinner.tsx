/**
 * The one turning thing in the portal.
 *
 * Extracted from `Button`, which had it inline, because `Loading` needs the
 * same mark and a second copy of a spinner is a second answer to "what does
 * waiting look like". Sized in `em` so it matches whatever text it sits
 * beside, and coloured with `currentColor` so it is the muted grey of a
 * loading line and the white of a primary button without either caller
 * saying so.
 *
 * `aria-hidden`, always. The meaning is carried by the sentence next to it —
 * a button's `aria-busy`, a loading region's `role="status"` — and a screen
 * reader that also announced the graphic would say the same thing twice.
 *
 * The global `prefers-reduced-motion` rule in index.css stops the animation
 * for anybody who asked for less of it, which leaves the mark drawn and
 * still. That is the intended outcome: it is a shape that says "waiting",
 * not only a movement.
 */
export function Spinner({ className }: { className?: string }) {
  return (
    <svg
      className={className ?? 'size-4 animate-spin'}
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"
      />
    </svg>
  )
}
