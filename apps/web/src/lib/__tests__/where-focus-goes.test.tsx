import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useRef, useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { useModalDialog } from '@/lib/useModalDialog'

/**
 * Where focus goes when a dialogue opens and closes.
 *
 * This is the most subtle mechanism in the portal's UI layer and the one with
 * the least visible failure mode: focus lands on `<body>`, nothing looks
 * wrong, and the next person to press Tab starts at the top of the document
 * twenty-eight stops from where they were. It cost four wrong fixes to get
 * right — the reasons are in the hook — so it is pinned here rather than left
 * to be re-derived.
 *
 * The two cases that actually broke are the last two: dismissal by unmounting,
 * which is how nearly every dialogue in this portal is dismissed, and the
 * StrictMode double-invoke that used to throw the remembered opener away.
 *
 * jsdom implements `<dialog>` enough for this — `showModal`, `close`, `open`,
 * and the `cancel` event — but not the focus trap, which is the browser's job
 * and is exercised by the keyboard journey in the browser suite instead.
 */

/** A dialogue that stays mounted and is driven by a prop. */
function AlwaysMounted() {
  const dialog = useRef<HTMLDialogElement>(null)
  const [open, setOpen] = useState(false)

  useModalDialog(dialog, open, () => { setOpen(false); })

  return (
    <div>
      <button type="button" onClick={() => { setOpen(true); }}>
        Force off
      </button>

      <dialog ref={dialog}>
        <button type="button" onClick={() => { setOpen(false); }}>
          Cancel
        </button>
      </dialog>
    </div>
  )
}

/**
 * A dialogue rendered only while it should be shown.
 *
 * The pattern most screens use, and the one the first three fixes missed:
 * dismissing does not set `open` to false, it unmounts the component.
 */
function MountedWhileOpen() {
  const [open, setOpen] = useState(false)

  return (
    <div>
      <button type="button" onClick={() => { setOpen(true); }}>
        Force off
      </button>

      {open ? <Dismissable onClose={() => { setOpen(false); }} /> : null}
    </div>
  )
}

function Dismissable({ onClose }: { onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null)

  useModalDialog(dialog, true, onClose)

  return (
    <dialog ref={dialog}>
      <button type="button" onClick={onClose}>
        Cancel
      </button>
    </dialog>
  )
}

describe('a dialogue driven by a prop', () => {
  it('opens as a modal, not as an inline box', async () => {
    const user = userEvent.setup()

    render(<AlwaysMounted />)

    await user.click(screen.getByRole('button', { name: 'Force off' }))

    // `showModal`, not `show`: the focus trap and the inert background behind
    // it are the whole reason for using the element.
    expect(screen.getByRole('dialog')).toBeVisible()
  })

  it('gives focus back to the control that opened it', async () => {
    const user = userEvent.setup()

    render(<AlwaysMounted />)

    const opener = screen.getByRole('button', { name: 'Force off' })

    opener.focus()
    await user.click(opener)

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(document.activeElement, 'focus is back on the opener').toBe(opener)
  })

  it('does not slam shut when the component re-renders while open', async () => {
    /*
     * The failure this guards was introduced by a fix for the one above:
     * branching the close path on the element's own state meant any re-render
     * during the open cycle — a `loading` prop arriving, say — took the
     * dialogue away mid-decision.
     */
    const user = userEvent.setup()

    function Rerendering() {
      const dialog = useRef<HTMLDialogElement>(null)
      const [open, setOpen] = useState(false)
      const [ticks, setTicks] = useState(0)

      useModalDialog(dialog, open, () => { setOpen(false); })

      return (
        <div>
          <button type="button" onClick={() => { setOpen(true); }}>
            Force off
          </button>
          <button type="button" onClick={() => { setTicks((n) => n + 1); }}>
            Re-render
          </button>
          <dialog ref={dialog}>
            <p>ticks: {ticks}</p>
          </dialog>
        </div>
      )
    }

    render(<Rerendering />)

    await user.click(screen.getByRole('button', { name: 'Force off' }))
    expect(screen.getByRole('dialog')).toBeVisible()

    await user.click(screen.getByRole('button', { name: 'Re-render' }))

    expect(screen.getByRole('dialog'), 'still open after a re-render').toBeVisible()
    expect(screen.getByText('ticks: 1')).toBeInTheDocument()
  })
})

describe('a dialogue mounted only while open', () => {
  it('gives focus back when dismissing unmounts it', async () => {
    /*
     * The case that took longest to find. `open` never becomes false — the
     * component goes away — so the restore has to happen in the effect's
     * cleanup, and it has to survive StrictMode's effect/cleanup/effect
     * double-invoke without losing the remembered opener.
     */
    const user = userEvent.setup()

    render(<MountedWhileOpen />)

    const opener = screen.getByRole('button', { name: 'Force off' })

    opener.focus()
    await user.click(opener)
    expect(screen.getByRole('dialog')).toBeVisible()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(document.activeElement, 'focus is back on the opener').toBe(opener)
  })
})

describe('Escape', () => {
  it('reaches the screen that owns the dialogue', () => {
    /*
     * React's `onCancel` prop never fires for a `<dialog>`: `cancel` does not
     * bubble, so a listener delegated to the root container never sees it. For
     * as long as the portal relied on it, Escape closed the element natively
     * while React went on believing the dialogue was open — so Escape was
     * silently a different thing from pressing Cancel, and the owning screen
     * was never told.
     */
    const dismissed = vi.fn()

    function WithSpy() {
      const dialog = useRef<HTMLDialogElement>(null)
      const [open, setOpen] = useState(true)

      useModalDialog(dialog, open, () => {
        dismissed()
        setOpen(false)
      })

      return <dialog ref={dialog}>body</dialog>
    }

    render(<WithSpy />)

    // Dispatched on the element, because that is where a non-bubbling event
    // arrives and the point of the test is that the listener is there.
    screen.getByRole('dialog', { hidden: true }).dispatchEvent(new Event('cancel', { cancelable: true }))

    expect(dismissed).toHaveBeenCalledTimes(1)
  })

  it('is prevented from closing the element behind React', () => {
    /*
     * If the element closed itself, React would still have `open` true, the
     * next render would try to reopen it, and the path that restores focus
     * would never run.
     */
    function WithSpy() {
      const dialog = useRef<HTMLDialogElement>(null)

      useModalDialog(dialog, true, () => undefined)

      return <dialog ref={dialog}>body</dialog>
    }

    render(<WithSpy />)

    const event = new Event('cancel', { cancelable: true })
    screen.getByRole('dialog', { hidden: true }).dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
  })
})
