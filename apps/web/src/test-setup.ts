import '@testing-library/jest-dom/vitest'
import { configure, queryAllByRole } from '@testing-library/react'

/**
 * jsdom does not implement `<dialog>`'s modal methods.
 *
 * The portal uses the native element deliberately — the browser owns the focus
 * trap, the inert background, Escape, and the accessibility tree, and those are
 * the four things a hand-rolled overlay gets subtly wrong. jsdom parses the
 * element but leaves showModal() and close() undefined, so a component test
 * that opens one throws.
 *
 * This fills in only what the tests need: the `open` attribute, which is what
 * `role="dialog"` visibility is derived from, and the cancel event that Escape
 * dispatches. It is deliberately not a full polyfill — the real behaviour is
 * asserted in the browser suite against Chromium, and a rich fake here would
 * only prove that the fake works.
 */
/*
 * Written against a widened alias of the prototype. The DOM lib declares these
 * methods as always present, so TypeScript narrows the object to `never`
 * inside any check for their absence — while jsdom is precisely the
 * environment where they are absent, which is why this file exists. The alias
 * says "this is the runtime object, not the declared type" once, rather than
 * casting at each assignment.
 */
const dialogPrototype = HTMLDialogElement.prototype as Partial<HTMLDialogElement>

if (dialogPrototype.showModal === undefined) {
  dialogPrototype.showModal = function showModal(this: HTMLDialogElement) {
    this.open = true
  }

  dialogPrototype.show = function show(this: HTMLDialogElement) {
    this.open = true
  }

  dialogPrototype.close = function close(this: HTMLDialogElement, returnValue?: string) {
    this.open = false

    if (returnValue !== undefined) this.returnValue = returnValue

    this.dispatchEvent(new Event('close'))
  }
}

/**
 * Pays the first accessible-name query's one-time cost here, where no clock is
 * running, instead of inside the first `findBy*` of every test file (F-42).
 *
 * `findBy*` is `waitFor` wrapped around a `getBy*` (`@testing-library/dom`
 * 10.4.1, `dist/query-helpers.js:84-93`), and `waitFor` starts its give-up
 * timer before it looks for the first time (`dist/wait-for.js:40`; the first
 * look is at `:97`). That timer is `asyncUtilTimeout`, 1000 ms by default
 * (`dist/config.js:15`) and 10,000 ms here since round three (see the end of
 * this file). It is not vitest's test timeout (30,000 ms, vitest.config.ts),
 * which is a separate clock. So whatever a query costs is spent out of that
 * budget.
 *
 * The first `*ByRole` query in a test file costs much more than the ones after
 * it. Unless it passes `hidden: true`, a role query calls `getComputedStyle` to
 * leave hidden elements out (`dist/role-helpers.js:61-67`). jsdom parses its
 * default user-agent stylesheet the first time `getComputedStyle` runs in a
 * process (jsdom 27.4.0, `lib/jsdom/living/helpers/style-rules.js:141-142`),
 * and every test file starts cold. Measured at 1fec2e5 on a four-core box at a
 * load average of 6.6-7.0, each figure from a fresh file against a DOM of six
 * elements:
 *
 *   - the first named `queryAllByRole` took 236-396 ms, and the second 3-10 ms;
 *   - a first `getComputedStyle` alone took 162-323 ms, and a named query
 *     straight after it took 12-13 ms.
 *
 * Under load that cost stretches while the timer keeps running. A test whose
 * element appears after its first look can then run out of its second before a
 * later look finds it, and fails with `Unable to find role="…"`. Most of the
 * load-triggered failures recorded for F-42 have that shape. The others were
 * vitest's own 5000 ms timeout in `userEvent`-heavy tests, which F-42's record
 * traces to a separate mechanism that this does not touch (its budget is now
 * set in vitest.config.ts). None has been an
 * assertion failure. At 1fec2e5, 321 of the 435 `*ByRole` calls in the 59
 * component specs pass a `name`, spread over 52 of those specs.
 *
 * One call is enough for every query family that pays the cost. A bare
 * `ByRole` query pays it (240-409 ms in the same runs) and leaves a later named
 * query warm (5-13 ms). `ByLabelText` does not call `getComputedStyle`, costs
 * 10-26 ms cold, and leaves the next named role query as cold as before. The
 * rest of a cold start, module and JIT warm-up spread over a file's first
 * several tests, cannot be reached from a setup file. There is no second
 * warm-up to write.
 *
 * What this does not do: it takes the cold-start share out of the budget, and
 * it does not make the suite immune to load. On a loaded enough machine a
 * `findBy*` could still run out of a one-second clock, and in round three it
 * did: two parallel runs at 81a56af, load average 5.3 rising to 16.2, failed
 * `Unable to find role="heading"` and `role="alert"` after 1.2-2.6 s, each
 * passing alone. So the clock is no longer the default; see
 * `ASYNC_UTIL_TIMEOUT_MS` below. `retry` is still left out on purpose: a retry
 * turns a broken test green on its second attempt.
 *
 * i18next is not a race here. With inline resources `init()` finishes
 * synchronously, so `i18n.isInitialized` is already true once `@/i18n` has
 * been imported, before any component renders.
 */
function warmTheAccessibleNameQuery(): void {
  const probe = document.createElement('nav')
  probe.innerHTML = '<a href="/">warm</a><button type="button">up</button>'
  document.body.append(probe)

  queryAllByRole(probe, 'link', { name: 'warm' })

  probe.remove()
}

warmTheAccessibleNameQuery()

/**
 * How long a `findBy*` or `waitFor` waits before giving up: 10,000 ms, chosen
 * (F-42), where it used to be Testing Library's default of 1,000 ms.
 *
 * Why raising it is not the retry this file refuses. A `findBy*` passes the
 * moment the element it asks for is there, so a longer clock changes nothing
 * for a test that passes; it only lets a slow machine finish the same work.
 * A test whose element never appears still fails, with the same message, ten
 * seconds later. No spec passes its own `timeout` or waits for something to
 * stay absent through the clock (checked with
 * `rg -n "timeout:|waitFor\(" src --glob '*.test.*'` when this was set), so
 * no assertion's meaning depends on the value.
 *
 * Why ten seconds. The load-triggered failures gave up at 1.2-2.6 s wall time
 * on work that takes 50-500 ms alone; the stretch measured under two parallel
 * runs at a load average of 12-16 was about 4-5x. Ten seconds is that stretch
 * over the slowest first render with room to spare, and stays well inside the
 * 30,000 ms per-test budget in vitest.config.ts, so a test that waits on two
 * or three answers in a row still fails on the missing element, with its own
 * message, and not on the test clock.
 *
 * the-suite-runs-on-budgets-somebody-chose.test.ts holds the value.
 */
export const ASYNC_UTIL_TIMEOUT_MS = 10_000

configure({ asyncUtilTimeout: ASYNC_UTIL_TIMEOUT_MS })
