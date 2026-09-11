import { createContext, useContext } from 'react'

import type { CustomerOperationState } from '@/lib/types'

/**
 * The handle screens use to start watching something.
 *
 * Separated from the provider component for the same reason the toast channel
 * is: a file that exports both a component and the hook its callers import
 * breaks fast refresh, and this repository carries no lint warnings.
 */

/** What the portal needs in order to keep watching something across a reload. */
export interface OperationWatch {
  id: string
  /**
   * Translation key for the thing that was asked for — `operations.actions.reboot`.
   *
   * A key rather than a sentence, because the message is assembled in the
   * reader's language at announce time, and because the customer may switch
   * language while a rebuild is running.
   */
  actionKey: string
  /** Where to go to see it. The resource's own page, by Wave 3's routing map. */
  href?: string | undefined
  /**
   * Query families to refresh when this finishes.
   *
   * Top-level key names, matching the convention the query layer already uses
   * — invalidating `['vps']` catches the list and every machine's detail page
   * without anybody maintaining a list of what a reboot affects.
   */
  invalidate?: string[] | undefined
}

/**
 * The least a 202 has to say for the portal to follow it up.
 *
 * Structural rather than one named resource type, because the receipts differ:
 * a VPS power action returns the operation with its verb and its service, a
 * dedicated rebuild returns a shorter one. Both name themselves and both say
 * whether there is anything left to wait for, which is all the watcher needs.
 */
export interface WatchableReceipt {
  id: string
  state: CustomerOperationState
  is_terminal: boolean
}

export interface WatchChannel {
  /**
   * Start watching, and acknowledge the request in the same breath.
   *
   * The acknowledgement comes from the receipt the server just returned, not
   * from a hopeful string: "Reboot requested" is what happened. "Success!" is
   * a claim about a machine nobody has heard from yet.
   */
  watch: (receipt: WatchableReceipt, watch: Omit<OperationWatch, 'id'>) => void

  /**
   * Say that a request was accepted, without following it up.
   *
   * For the actions whose progress is a property of the resource rather than
   * of an operation the platform will report on — a dedicated chassis's power
   * state, which the controller reports and the machine's own page shows. The
   * customer still deserves to be told their press was received; what they
   * must not be told is a result nobody has.
   */
  acknowledge: (subject: string, watch: Omit<OperationWatch, 'id'>) => void
}

export const WatchContext = createContext<WatchChannel | null>(null)

const NOT_WATCHING: WatchChannel = { watch: () => undefined, acknowledge: () => undefined }

export function useWatchOperations(): WatchChannel {
  return useContext(WatchContext) ?? NOT_WATCHING
}
