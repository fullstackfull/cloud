import { useEffect, useRef, type RefObject } from 'react'

/**
 * Drives a native `<dialog>` from a boolean: opens it, closes it, tells the
 * caller when the customer dismissed it, and gives focus back afterwards.
 *
 * One hook rather than three, because the ordering between opening, closing,
 * dismissing and restoring focus is the whole problem.
 *
 * ## The defect this exists for
 *
 * Measured in Chromium against the real portal, by keyboard: tab to "Force
 * off", press Enter, press Escape, and `document.activeElement` is `<body>`.
 * The customer's next Tab starts at the top of the document, twenty-eight
 * stops from the control they had been on. A dialogue is supposed to give
 * focus back, and this one did not.
 *
 * Finding out why cost four wrong answers. Each is recorded because each
 * looked like the right one:
 *
 *  1. **Restore from an effect on `open`.** Effects run in declaration order,
 *     so the restore ran and then the effect calling `close()` ran, and
 *     closing moved focus to `<body>` again. Correct, and immediately undone.
 *
 *  2. **Restore from the element's `close` event.** The right hook point in
 *     principle. Instrumenting it showed the listener attached and never
 *     called.
 *
 *  3. **React's `onCancel` prop.** It never fires: `cancel` does not bubble,
 *     so a listener delegated to the root container never sees it. Escape was
 *     closing the element natively while React went on believing the dialogue
 *     was open — which also meant a dialogue dismissed by Escape was silently
 *     a different thing from one dismissed by pressing Cancel, and the owning
 *     screen never heard about it. Hence the native `cancel` listener below.
 *
 *  4. **Branching the close path on `element.open`.** Guarded that way the
 *     restore never runs after Escape, because Escape has already closed the
 *     element. Guarded the other way — acting whenever `element.open` is
 *     true — it slams the dialogue shut the moment a `loading` prop changes.
 *     The branch belongs on `open`, the intent; the element's state is what
 *     this brings into line with it.
 *
 * ## Why the open transition is tracked explicitly
 *
 * The actual cause, and two things conspire in it.
 *
 * Most screens render a dialogue only while it should be shown —
 * `{confirming ? <ConfirmDialog open … /> : null}` — so dismissing it does not
 * set `open` to false, it *unmounts the component*. The element leaves the DOM
 * while still open, taking the focus inside it nowhere, and no close branch
 * runs because there is nothing left to run it. So the restore has to happen
 * in the effect's cleanup as well.
 *
 * But React's StrictMode double-invokes effects in development: effect,
 * cleanup, effect. A cleanup that restored focus and cleared the remembered
 * opener therefore threw it away before the dialogue had been dismissed at
 * all, and the second run — seeing the element already open — did not capture
 * it again. The opener was null by the time it was needed.
 *
 * So: the open transition lives in a ref rather than being inferred from the
 * element, the opener is captured on a genuine closed-to-open change, and the
 * finish path runs exactly once per cycle whether it is reached by `open`
 * going false or by the component going away.
 *
 * ## What it leaves to the browser
 *
 * Everything that works: `showModal()` moves focus into the dialogue, traps it
 * there, makes the page behind inert, puts it in the top layer, and fires
 * `cancel` on Escape. Those are the reasons to use the element and they are
 * not reimplemented here.
 *
 * @param dialog the `<dialog>` element this dialogue renders
 * @param open whether it should be open
 * @param onDismiss called when the customer dismisses it with Escape
 */
export function useModalDialog(
  dialog: RefObject<HTMLDialogElement | null>,
  open: boolean,
  onDismiss?: () => void,
): void {
  const opener = useRef<HTMLElement | null>(null)
  const wasOpen = useRef(false)

  // Held in a ref so that changing the callback does not detach and reattach
  // the listener, and so the listener always calls the current one.
  const dismiss = useRef(onDismiss)
  dismiss.current = onDismiss

  useEffect(() => {
    const element = dialog.current

    if (element === null) return

    const cancelled = (event: Event): void => {
      /*
       * The element is not allowed to close itself. Letting it would take the
       * dialogue away while React still had `open` true, so the next render
       * would try to reopen it and the finish path would never run.
       */
      event.preventDefault()
      dismiss.current?.()
    }

    element.addEventListener('cancel', cancelled)

    return () => {
      element.removeEventListener('cancel', cancelled)
    }
  }, [dialog])

  useEffect(() => {
    const element = dialog.current

    if (element === null) return

    /** Closes if needed and hands focus back. Runs once per open cycle. */
    const finish = (): void => {
      if (! wasOpen.current) return

      wasOpen.current = false

      if (element.open) element.close()

      const back = opener.current

      opener.current = null

      /*
       * `isConnected` first. An opener can legitimately be gone by the time
       * the dialogue closes — the row it sat in was removed, the section
       * re-rendered — and focusing a detached node silently does nothing
       * while looking like it worked.
       */
      if (back !== null && back.isConnected) back.focus()
    }

    if (! open) {
      finish()

      return
    }

    if (! wasOpen.current) {
      wasOpen.current = true

      // Read before `showModal()` moves focus inside.
      opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
    }

    if (! element.open) {
      // showModal, not show: the difference is the focus trap and the inert
      // background, which is the entire reason for using the element.
      element.showModal()
    }

    // Unmounting while open is how most of these dialogues are dismissed.
    return finish
  }, [dialog, open])
}
