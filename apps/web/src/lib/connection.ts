/**
 * Whether the browser believes it has a network.
 *
 * `navigator.onLine` is asymmetric and it matters which way: `false` is
 * trustworthy — the operating system has told the browser there is no route
 * out — while `true` only means an interface is up, not that the internet or
 * this platform is reachable. So being offline is reported as a fact and being
 * online is never reported at all; a request that fails while `onLine` is true
 * is a failure to reach *us*, which is a different sentence.
 *
 * Its own module so that a component file exports only components.
 */

type Listener = (offline: boolean) => void

const listeners = new Set<Listener>()

function broadcast(): void {
  const offline = isOffline()

  for (const listener of listeners) {
    listener(offline)
  }
}

let attached = false

function attach(): void {
  if (attached || typeof window === 'undefined') return

  window.addEventListener('online', broadcast)
  window.addEventListener('offline', broadcast)
  attached = true
}

/** True only when the browser is certain there is no network. */
export function isOffline(): boolean {
  // `onLine` is absent in some test environments; absent means "no opinion",
  // which must read as online rather than as a permanent offline banner.
  return typeof navigator !== 'undefined' && ! navigator.onLine
}

export function watchConnection(listener: Listener): () => void {
  attach()
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}
