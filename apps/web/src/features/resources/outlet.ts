import { useOutletContext } from 'react-router'

/**
 * How a resource page hands its subject to its sections.
 *
 * The sections of a resource page are routes, so they are separate components
 * rendered by an `<Outlet>` rather than children of the page. Each one needs
 * the thing the page is about — the machine, the account, the domain — and the
 * shell has already fetched it. Passing it through the outlet keeps that at one
 * request per visit: six sections each calling the same detail hook would be
 * six requests for one screen, which is the request explosion a resource page
 * invites if nobody decides where the fetch lives.
 *
 * One module for all six families rather than one context per family: the
 * shape is identical and six copies would drift.
 */
export interface ResourceOutlet<T> {
  resource: T
}

/*
 * The caller names the type it expects, exactly as react-router's own
 * useOutletContext does: the value comes from the parent route at runtime and
 * no signature can prove it. The parent asserts the other half with
 * `satisfies ResourceOutlet<…>` on the context it passes, so the two sides are
 * checked against the same interface even though this call is an assertion.
 */
// eslint-disable-next-line @typescript-eslint/no-unnecessary-type-parameters
export function useResource<T>(): T {
  return useOutletContext<ResourceOutlet<T>>().resource
}
