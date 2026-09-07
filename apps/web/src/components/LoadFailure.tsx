import { Alert } from '@/components/Alert'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Why a screen has nothing to show, when the reason is that the read failed.
 *
 * A failed query leaves its data undefined, and a table handed undefined
 * renders its empty state — so a 403, an expired session, a network drop and a
 * database that is down all read as "you have nothing here". That is the same
 * defect as a funded wallet reporting no balance: the screen states something
 * false, confidently, with nothing to tell the reader that anything went wrong.
 * On an operator screen it is worse than confusing, since "no compute nodes"
 * and "you are not allowed to see the compute nodes" are opposite facts.
 *
 * Rendered above the data rather than instead of it: a paginated screen whose
 * refresh fails should keep showing what it already had and say the refresh
 * failed, not blank itself.
 *
 * Nothing at all when there is no error, so it costs a screen one line.
 */
export function LoadFailure({ error }: { error: unknown }) {
  const describeError = useApiErrorMessage()
  const failure = describeError(error)

  if (failure === null) return null

  return (
    <div className="mb-4">
      <Alert tone="error" requestId={failure.requestId}>
        {failure.message}
      </Alert>
    </div>
  )
}
