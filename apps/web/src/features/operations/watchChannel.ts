import { createContext, useContext } from 'react'

import type { AcceptedOperation } from '@/lib/types'

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

export interface WatchChannel {
  /**
   * Start watching, and acknowledge the request in the same breath.
   *
   * The acknowledgement comes from the receipt the server just returned, not
   * from a hopeful string: "Reboot requested" is what happened. "Success!" is
   * a claim about a machine nobody has heard from yet.
   */
  watch: (receipt: AcceptedOperation, watch: Omit<OperationWatch, 'id'>) => void
}

export const WatchContext = createContext<WatchChannel | null>(null)

const NOT_WATCHING: WatchChannel = { watch: () => undefined }

export function useWatchOperations(): WatchChannel {
  return useContext(WatchContext) ?? NOT_WATCHING
}
