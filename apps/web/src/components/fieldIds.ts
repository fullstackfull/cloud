/**
 * The `aria-describedby` value for a form control with a hint, an error, or
 * both.
 *
 * Its own module because every field component needs it and a component file
 * that also exports a plain function stops React's fast refresh from working.
 *
 * Returns `undefined` rather than an empty string when there is nothing to
 * describe: `aria-describedby=""` is a reference to a node with no id, which
 * some screen readers announce as an empty description rather than as none.
 */
export function describedBy(ids: Array<string | null>): string | undefined {
  const present = ids.filter((id): id is string => id !== null)

  return present.length === 0 ? undefined : present.join(' ')
}
