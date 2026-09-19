import { Badge } from '@/components/Badge'
import { safeLabel } from '@/lib/safeLabel'
import { toneFor } from '@/lib/statusVocabulary'

/**
 * Statuses arrive as the server's own vocabulary and are translated by key,
 * toned by the shared vocabulary in `lib/statusVocabulary`.
 *
 * An unrecognised value reads as "an unrecognised state" rather than as the
 * value itself. It used to render `status.replace(/_/g, ' ')` — `needs_review`
 * became "needs review", which looks enough like a label that nobody reports
 * it, and on an Arabic page it is an English phrase in a badge. The parity
 * test on the vocabulary makes that path unreachable for every status the API
 * is known to publish; this is what a customer sees if one ever escapes.
 */
export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={toneFor(status)}>{safeLabel('status', status)}</Badge>
}
