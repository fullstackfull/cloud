import { useState } from 'react'

import { useStartPayment } from '@/lib/queries'
import type { Payment, PaymentNextActionType } from '@/lib/types'

/**
 * What the portal is allowed to do about a payment, and nothing more.
 *
 * The server answers "pay this invoice" with a typed next action, and each of
 * the five answers is a different screen. Collapsing them — which is what the
 * portal used to do, by looking for a redirect URL and doing nothing when
 * there was none — produces the worst possible behaviour in the two cases that
 * matter most: a declined card looks like a button that does nothing, and a
 * payment the provider has not decided yet looks the same, so the customer
 * presses it again.
 *
 * Nothing here decides that an invoice is paid. `completed` means the provider
 * says it has the money; whether the invoice is settled is read back from the
 * invoice, which the platform updates when the provider confirms it server to
 * server.
 */
export type PaymentLaunchState =
  | { type: 'idle' }
  /** Leaving for the provider's page. The browser is already on its way. */
  | { type: 'redirecting'; url: string }
  /** The provider wants the payment confirmed from here, with its credential. */
  | {
      type: 'client_confirmation'
      reference: string
      clientSecret: string
      payment: Payment
    }
  /** The provider has taken it. The platform is still confirming. */
  | { type: 'completed'; payment: Payment }
  /** Refused, with the provider's reason. */
  | { type: 'failed'; payment: Payment; failureCode: string | null; failureMessage: string | null }
  /** Nobody knows yet. Do not start another payment. */
  | { type: 'pending'; payment: Payment }

export interface PaymentLaunch {
  state: PaymentLaunchState
  isPending: boolean
  error: unknown
  start: (invoiceId: string) => Promise<void>
  reset: () => void
}

export function usePaymentLaunch(): PaymentLaunch {
  const startPayment = useStartPayment()
  const [state, setState] = useState<PaymentLaunchState>({ type: 'idle' })

  async function start(invoiceId: string): Promise<void> {
    const started = await startPayment.mutateAsync(invoiceId).catch(() => null)

    if (started === null) {
      setState({ type: 'idle' })
      return
    }

    const action = started.next_action
    const type: PaymentNextActionType = action.type

    if (type === 'redirect' && action.redirect_url !== null && action.redirect_url !== '') {
      setState({ type: 'redirecting', url: action.redirect_url })
      window.location.assign(action.redirect_url)
      return
    }

    if (type === 'client_confirmation' && action.client_secret !== null && action.client_secret !== '') {
      setState({
        type: 'client_confirmation',
        reference: started.reference,
        clientSecret: action.client_secret,
        payment: started.payment,
      })
      return
    }

    if (type === 'completed') {
      setState({ type: 'completed', payment: started.payment })
      return
    }

    if (type === 'failed') {
      setState({
        type: 'failed',
        payment: started.payment,
        failureCode: started.failure_code,
        failureMessage: started.failure_message,
      })
      return
    }

    /*
     * Everything else — including a redirect the provider promised and did not
     * send a URL for — is treated as "waiting on the provider". It is the safe
     * reading: the money may already be moving, so the screen says wait rather
     * than offering a second attempt.
     */
    setState({ type: 'pending', payment: started.payment })
  }

  return {
    state,
    isPending: startPayment.isPending,
    error: startPayment.error,
    start,
    reset: () => { setState({ type: 'idle' }); },
  }
}
