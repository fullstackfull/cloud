import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { ConfirmDialog } from '@/components/ConfirmDialog'
import '@/i18n'

/*
 * A confirm button with nothing behind it must not be pressable.
 *
 * The invoice credit dialogue used to enable its button while the quote was
 * still loading, and pressing it then did nothing — no request, no message.
 * A browser test that clicked at that moment waited ten seconds for an
 * outcome nobody had asked for. `ready` is the dialog's own statement that
 * confirming would do something.
 */
describe('ConfirmDialog readiness', () => {
  function renderDialog(ready: boolean | undefined, onConfirm = vi.fn()) {
    render(
      <ConfirmDialog
        open
        title="Use credit"
        body={<p>Loading…</p>}
        confirmLabel="Use credit"
        {...(ready === undefined ? {} : { ready })}
        onConfirm={onConfirm}
        onCancel={() => undefined}
      />,
    )

    return onConfirm
  }

  it('disables confirmation while the dialog says it is not ready', async () => {
    const onConfirm = renderDialog(false)
    const confirm = screen.getByRole('button', { name: /^use credit$/i })

    expect(confirm).toBeDisabled()

    await userEvent.click(confirm)

    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('enables confirmation once ready, and by default', async () => {
    const onConfirm = renderDialog(true)

    await userEvent.click(screen.getByRole('button', { name: /^use credit$/i }))
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('treats an unstated readiness as ready, so dialogs with no asynchronous body need no change', () => {
    renderDialog(undefined)

    expect(screen.getByRole('button', { name: /^use credit$/i })).toBeEnabled()
  })

  it('keeps cancel available while not ready — waiting must never trap the customer', () => {
    renderDialog(false)

    expect(screen.getByRole('button', { name: /cancel/i })).toBeEnabled()
  })
})
