import { useEffect } from 'react'

/**
 * Warns before the browser throws away something the customer has typed.
 *
 * ## What this is for
 *
 * Several forms in the portal hold real work. A support request describing a
 * fault, a pasted zone file, a list of nameservers: minutes of typing, and
 * nothing on the page suggests that closing the tab loses it. Reloading to
 * "see if that fixes it" is the single most common thing a customer does when
 * a page misbehaves, which means the moment they are most likely to discard a
 * half-written request is the moment they are most annoyed.
 *
 * ## What it deliberately does not do
 *
 * It does not block in-application navigation. React Router's `useBlocker`
 * needs a data router, and this application mounts `<BrowserRouter>`; moving
 * to `createBrowserRouter` to intercept a click on "Dashboard" is a routing
 * refactor rather than a polish item, and it is recorded as a known gap rather
 * than half-done here. So: closing the tab, reloading, or following a link out
 * of the portal is guarded; clicking a sidebar link is not.
 *
 * It also does not save a draft anywhere. A draft kept in browser storage is a
 * copy of what a customer wrote living on a shared machine after they have
 * walked away from it, and one of these forms is where somebody describes
 * their own infrastructure. The prompt is the honest amount of help.
 *
 * ## The browser decides the words
 *
 * Every current browser ignores any custom message and shows its own. Passing
 * one would be writing a sentence nobody ever reads — so this takes no copy
 * argument, and the only question it answers is *whether* to prompt.
 *
 * @param dirty whether there is unsaved input to lose right now
 */
export function useUnsavedChanges(dirty: boolean): void {
  useEffect(() => {
    if (! dirty) return

    /*
     * `preventDefault()` alone, which is what the specification says and what
     * every browser this portal already requires honours. The old idiom also
     * assigned `event.returnValue`, for browsers that predate that — but the
     * portal reads time zones from `Intl.supportedValuesOf`, which rules those
     * browsers out several versions earlier, so the legacy line would be a
     * deprecated assignment kept for a browser that cannot load the page.
     */
    const warn = (event: BeforeUnloadEvent): void => {
      event.preventDefault()
    }

    window.addEventListener('beforeunload', warn)

    return () => {
      window.removeEventListener('beforeunload', warn)
    }
  }, [dirty])
}
