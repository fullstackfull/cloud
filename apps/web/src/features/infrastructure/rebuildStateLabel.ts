import i18n from '@/i18n'
import { safeLabel } from '@/lib/safeLabel'

/**
 * What a rebuild's state is called, read from the pair the API publishes
 * rather than from the state name alone (F-20).
 *
 * `failed` is published for two different rebuilds: one that stopped before
 * the machine was told to replace its disk, and one an operator settled
 * `failed` after it had been. The state's own label, "The rebuild did not
 * run", is true of the first and false of the second, and the second is the
 * one the audit quoted. `data_destroyed` is what tells them apart, so this
 * reads it: `failed` with the disk gone is named "The rebuild stopped after
 * erasing the disk" (the disks, on a dedicated server), and every other pair
 * keeps the state's own label.
 *
 * Why only that pair. At every other state the API can publish today the
 * label already agrees with the fact: the in-flight destructive states name
 * the replacement, `completed` says "Rebuilt", `needs_review` and
 * `indeterminate` say a person is looking, and the states before the
 * destructive call are never published destroyed. The dedicated states
 * `hardware_unavailable` ("nothing was changed") and `provisioning_timeout`
 * are not renamed: DedicatedReinstallStateMachine enters the first only from
 * states before the power cycle, so it is not published destroyed, and the
 * second does not say the disks are intact. whether-the-disk-is-already-gone.test.tsx
 * holds the VPS pairs and the dedicated `(failed, true)` pair; it cannot see
 * a pair the API does not yet publish.
 *
 * The replacement key lives outside the `reinstallState` namespaces on
 * purpose: those are bound one-to-one to the backend enums by
 * EveryStateAScreenShowsIsTranslatedTest, and this is not a state.
 */
export function rebuildStateLabel(
  kind: 'vps' | 'dedicated',
  rebuild: { state: string; data_destroyed: boolean },
): string {
  if (stoppedAfterErasing(rebuild)) return i18n.t(`${kind}.rebuildStoppedAfterErasing`)

  return safeLabel(`${kind}.reinstallState`, rebuild.state)
}

/**
 * Whether this is the rebuild whose state name alone would have told the
 * customer nothing was touched. Screens use it to present the label as harm.
 */
export function stoppedAfterErasing(rebuild: { state: string; data_destroyed: boolean }): boolean {
  return rebuild.state === 'failed' && rebuild.data_destroyed
}
