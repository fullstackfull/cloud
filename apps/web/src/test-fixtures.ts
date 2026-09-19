import type { AccountOverview } from '@/lib/types'

/**
 * Payloads several tests need only because they render the whole application.
 *
 * A test about the phone drawer is not a test about the dashboard, but the
 * drawer only exists inside the signed-in shell and the shell's first route is
 * the dashboard — so every such test has to answer the dashboard's one
 * request. Written once, here, rather than pasted into each file, because the
 * day the contract changes is the day all of them should fail together for the
 * same reason.
 *
 * Deliberately the empty shape rather than a populated one: a test that is
 * about something else should render the quietest possible dashboard.
 */
export const EMPTY_OVERVIEW: AccountOverview = {
  attention: [],
  services: { total: 0, by_state: {} },
  billing: { due: [] },
  renewals: [],
  unread_notifications: 0,
  recent: { services: [], activity: [] },
}

/** The routes the signed-in shell reads on every page, and nothing more. */
export const SHELL_ROUTES: Record<string, { status: number; body?: unknown }> = {
  '/me/overview': { status: 200, body: { data: EMPTY_OVERVIEW } },
  '/notifications/unread-count': { status: 200, body: { data: { unread: 0 } } },
}
