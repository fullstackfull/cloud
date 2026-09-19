import { render } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { useUnsavedChanges } from '@/lib/useUnsavedChanges'

/**
 * The guard on work a customer has typed and not sent.
 *
 * Reloading to "see if that fixes it" is the first thing anybody does when a
 * page misbehaves, which makes the moment they are most likely to discard a
 * half-written support request the moment they are most annoyed about the
 * fault it describes.
 *
 * What is asserted is narrow on purpose: that the prompt exists exactly while
 * there is something to lose, and that it is torn down when there is not. The
 * words belong to the browser — every current one ignores a custom message —
 * so there is no copy to test, and the hook takes none.
 */

function Form({ typed }: { typed: string }) {
  useUnsavedChanges(typed.trim() !== '')

  return <p>{typed}</p>
}

function Editable() {
  const [typed, setTyped] = useState('')

  return (
    <div>
      <button type="button" onClick={() => { setTyped('a described fault'); }}>
        type
      </button>
      <button type="button" onClick={() => { setTyped(''); }}>
        clear
      </button>
      <Form typed={typed} />
    </div>
  )
}

describe('leaving a page with unsaved input', () => {
  it('registers no prompt while there is nothing to lose', () => {
    const add = vi.spyOn(window, 'addEventListener')

    render(<Form typed="" />)

    expect(add.mock.calls.filter(([name]) => name === 'beforeunload')).toHaveLength(0)

    add.mockRestore()
  })

  it('registers one once something has been typed', () => {
    const add = vi.spyOn(window, 'addEventListener')

    render(<Form typed="a described fault" />)

    expect(add.mock.calls.filter(([name]) => name === 'beforeunload')).toHaveLength(1)

    add.mockRestore()
  })

  it('treats whitespace as nothing, so a stray space does not nag', () => {
    const add = vi.spyOn(window, 'addEventListener')

    render(<Form typed="   " />)

    expect(add.mock.calls.filter(([name]) => name === 'beforeunload')).toHaveLength(0)

    add.mockRestore()
  })

  it('asks the browser to prompt, the way the specification says', () => {
    /*
     * `preventDefault()` is the signal; the old `returnValue` assignment is
     * deprecated and is for browsers that predate the APIs this portal already
     * depends on. Asserted because the failure mode is silent: a handler that
     * does neither is a handler that never prompts.
     */
    const handlers: Array<(event: BeforeUnloadEvent) => void> = []

    vi.spyOn(window, 'addEventListener').mockImplementation(((name: string, handler: unknown) => {
      if (name === 'beforeunload') handlers.push(handler as (event: BeforeUnloadEvent) => void)
    }) as typeof window.addEventListener)

    render(<Form typed="a described fault" />)

    expect(handlers).toHaveLength(1)

    const prevented = vi.fn()
    const event = { preventDefault: prevented } as unknown as BeforeUnloadEvent

    handlers[0]?.(event)

    expect(prevented).toHaveBeenCalled()

    vi.restoreAllMocks()
  })

  it('stops prompting once the input is gone', () => {
    const remove = vi.spyOn(window, 'removeEventListener')

    const { unmount } = render(<Form typed="a described fault" />)

    unmount()

    expect(remove.mock.calls.filter(([name]) => name === 'beforeunload')).toHaveLength(1)

    remove.mockRestore()
  })

  it('follows the input rather than the mount', async () => {
    // Typing and then clearing must leave nothing behind: a prompt that
    // outlives the text is a page that cannot be left.
    const { default: userEvent } = await import('@testing-library/user-event')
    const user = userEvent.setup()

    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')

    const { getByRole } = render(<Editable />)

    await user.click(getByRole('button', { name: 'type' }))
    expect(add.mock.calls.filter(([name]) => name === 'beforeunload')).toHaveLength(1)

    await user.click(getByRole('button', { name: 'clear' }))
    expect(remove.mock.calls.filter(([name]) => name === 'beforeunload')).toHaveLength(1)

    add.mockRestore()
    remove.mockRestore()
  })
})
