import { createContext, useContext } from 'react'

/**
 * The types and the handle for the portal's feedback channel.
 *
 * Split from `Toasts.tsx` so that the component file exports only components:
 * a module that mixes a provider with the hook its consumers import defeats
 * fast refresh, which is a development annoyance rather than a bug, but the
 * repository carries no lint warnings and this one is not worth being the
 * first.
 */

export type ToastTone = 'info' | 'success' | 'warning' | 'danger'

export interface Toast {
  /**
   * What this message is about, not when it was made.
   *
   * The operation id, the resource id — something stable, so that the same
   * subject updates its own message rather than adding another.
   */
  id: string
  tone: ToastTone
  title: string
  body?: string | undefined
  /** Somewhere to go about it: the resource's own page, or a support form. */
  action?: { label: string; to: string } | undefined
}

export interface ToastChannel {
  announce: (toast: Toast) => void
  dismiss: (id: string) => void
}

export const ToastContext = createContext<ToastChannel | null>(null)

const NO_CHANNEL: ToastChannel = {
  announce: () => undefined,
  dismiss: () => undefined,
}

/**
 * The channel, or a no-op.
 *
 * A component outside the provider — a unit test rendering one card, a
 * print-only route — should not crash for want of a toast host. It should
 * simply have nowhere to announce to.
 */
export function useToasts(): ToastChannel {
  return useContext(ToastContext) ?? NO_CHANNEL
}
