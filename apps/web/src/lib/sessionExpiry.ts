/**
 * The signal that the session behind this page has gone.
 *
 * A 401 arriving while the customer reads a page is a specific event with a
 * specific remedy, and the portal used to have no idea it had happened: the
 * only consumer of `ApiError.isUnauthenticated` was the profile probe, which
 * mapped its own 401 to "signed out" and told nobody. Every other query on the
 * page just failed, so a customer whose session expired while they read an
 * invoice saw a screen of error messages and no way to understand them.
 *
 * The transport raises this once per 401. What it deliberately does not do is
 * act: it does not redirect, does not clear caches, and above all does not
 * remember and replay the request that was refused. A `POST /vps/{id}/power`
 * that came back 401 either reached the server or did not, and re-sending it
 * after re-authentication would be the portal deciding to reboot a machine on
 * the strength of a click from before the session ended.
 *
 * Its own module, with no imports, so that the API client can raise it without
 * depending on React and a component can listen without depending on the API
 * client.
 */

type Listener = () => void

const listeners = new Set<Listener>()

/** Raised by the transport when a request is refused for want of a session. */
export function reportSessionExpired(): void {
  for (const listener of listeners) {
    listener()
  }
}

export function watchSessionExpiry(listener: Listener): () => void {
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}
