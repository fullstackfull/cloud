import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { act } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ToastProvider } from '@/components/Toasts'
import { useToasts, type Toast } from '@/components/toastChannel'
import '@/i18n'

/**
 * W5.6 item 10. What somebody listening hears while work happens.
 *
 * The async story is told entirely through one live region, which makes it
 * one of the few places where an accessibility mistake is not cosmetic: a
 * region that is impolite interrupts whatever sentence is being read; a region
 * added to the DOM at the same moment as its first message is a region
 * assistive technology was not watching when the message arrived, and the
 * message is simply never heard; and a poll that re-announces a state nobody
 * changed turns a running rebuild into the same sentence four times.
 *
 * Each of those is asserted here rather than described, because all three look
 * identical on screen — the message appears, and it looks right.
 */

/** A consumer that announces on command, so the tests drive the real channel. */
function Harness({ onReady }: { onReady: (announce: (toast: Toast) => void) => void }) {
  const { announce } = useToasts()

  onReady(announce)

  return null
}

function mount() {
  let announce: (toast: Toast) => void = () => undefined

  render(
    <MemoryRouter>
      <ToastProvider>
        <Harness
          onReady={(fn) => {
            announce = fn
          }}
        />
      </ToastProvider>
    </MemoryRouter>,
  )

  return {
    announce: (toast: Toast) => {
      act(() => {
        announce(toast)
      })
    },
  }
}

function messages(): HTMLElement[] {
  return screen.getAllByRole('listitem')
}

describe('the channel a screen reader listens to', () => {
  it('is present and polite before anything has happened', () => {
    mount()

    // In the DOM from the start. A live region created together with its first
    // message is announced by nothing: there was no region to observe.
    const region = screen.getByRole('region', { name: 'Updates' })
    const live = region.querySelector('[aria-live]')

    expect(live).not.toBeNull()

    /*
     * Polite, not assertive. An operation finishing is worth saying at the
     * next pause; interrupting a half-read sentence to say "Reboot completed"
     * is not the same favour.
     */
    expect(live).toHaveAttribute('aria-live', 'polite')

    // Additions are read as they arrive rather than re-reading the whole list.
    expect(live).toHaveAttribute('aria-atomic', 'false')
  })

  it('replaces a message about the same thing instead of stacking another', () => {
    const { announce } = mount()

    announce({ id: 'op-1', tone: 'info', title: 'Reboot requested' })
    announce({ id: 'op-1', tone: 'success', title: 'Reboot completed' })

    expect(messages()).toHaveLength(1)
    expect(messages()[0]).toHaveTextContent('Reboot completed')
  })

  it('says nothing new when a poll finds the state unchanged', () => {
    /*
     * The property a live region makes fragile. Announcing the same sentence
     * about the same subject must leave the region's text untouched, because
     * text that is rewritten is text that is read out again — and a rebuild
     * polled every few seconds would be announced for as long as it ran.
     */
    const { announce } = mount()

    announce({ id: 'op-1', tone: 'info', title: 'Rebuild requested' })

    const first = messages()[0]
    const before = first?.textContent

    announce({ id: 'op-1', tone: 'info', title: 'Rebuild requested' })

    expect(messages()).toHaveLength(1)

    // The same node, not a replacement carrying the same words: a removal and
    // an insertion is a change the region reports.
    expect(messages()[0]).toBe(first)
    expect(messages()[0]?.textContent).toBe(before)
  })

  it('keeps messages about different subjects apart', () => {
    const { announce } = mount()

    announce({ id: 'op-1', tone: 'info', title: 'Reboot requested' })
    announce({ id: 'op-2', tone: 'info', title: 'Rebuild requested' })

    expect(messages()).toHaveLength(2)

    // Newest last, which is the order the region reads them in.
    expect(messages()[1]).toHaveTextContent('Rebuild requested')
  })

  it('offers a dismiss with a name, reachable without a mouse', async () => {
    const user = userEvent.setup()
    const { announce } = mount()

    announce({ id: 'op-1', tone: 'danger', title: 'Reboot did not finish' })

    /*
     * The glyph is `aria-hidden`, so the name comes from the visually hidden
     * word beside it. An icon button with neither is announced as "button".
     */
    const dismiss = within(messages()[0] as HTMLElement).getByRole('button', { name: 'Dismiss' })

    dismiss.focus()
    expect(document.activeElement).toBe(dismiss)

    await user.keyboard('{Enter}')

    expect(screen.queryAllByRole('listitem')).toHaveLength(0)
  })
})

describe('which messages clear themselves', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('takes good news away and leaves bad news to be read', () => {
    /*
     * Not a nicety: the message a customer must not miss is the one saying
     * their machine needs somebody to look at it, and somebody reading with a
     * screen reader or a magnifier reaches it later than somebody glancing at
     * the corner of the screen. So a success clears itself and a warning or a
     * failure waits to be dismissed.
     */
    const { announce } = mount()

    announce({ id: 'good', tone: 'success', title: 'Reboot completed' })
    announce({ id: 'bad', tone: 'warning', title: 'Rebuild needs review' })

    expect(screen.getAllByRole('listitem')).toHaveLength(2)

    act(() => {
      vi.advanceTimersByTime(10_000)
    })

    const left = screen.getAllByRole('listitem')

    expect(left).toHaveLength(1)
    expect(left[0]).toHaveTextContent('Rebuild needs review')
  })
})
