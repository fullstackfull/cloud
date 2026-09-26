import { defineConfig, mergeConfig } from 'vitest/config'

import viteConfig from './vite.config'

/*
 * Test configuration is kept out of vite.config.ts on purpose: Vite's own
 * config type has no `test` key, so inlining it there makes the production
 * build config fail typechecking.
 */

/**
 * The budgets this suite runs on, chosen rather than inherited (F-42).
 *
 * Until round three the suite ran on vitest's defaults: 5,000 ms per test and
 * per hook, and as many forks as the machine has cores less one. On a
 * four-core box shared with other work that made the required gate red on
 * load alone. Measured at 81a56af, two `npx vitest run` side by side, load
 * average 5.3 at the start and 16.2 at the end, ~330 s each: both runs red,
 * 7 and 3 failures, every one a clock running out and none an assertion —
 * `Test timed out in 5000ms` (the F-21 compiler gate at 7.9 and 10.5 s, and
 * three `userEvent`-heavy tests at 5.2-5.3 s), and `Unable to find role=…`
 * from Testing Library's own one-second clock (see `test-setup.ts`). Every
 * failing file passes alone.
 *
 * - `testTimeout` / `hookTimeout`: 30,000 ms. The slowest test in the suite
 *   in isolation is the F-21 gate at 1.5-2.5 s, which carries its own larger
 *   budget; everything else finishes well under 1.5 s alone. The measured
 *   stretch under the load above was about 4-5x, and the slowest ordinary
 *   test measured under load since is 9.5 s, so 30 s leaves headroom for a
 *   machine loaded several times over, while a test that hangs still fails
 *   in half a minute rather than never.
 * - `pool: 'forks'` with `maxWorkers: '50%'`: two forks on a four-core box,
 *   where the default was three. These tests are CPU-bound (jsdom, React, the
 *   TypeScript compiler); a fork that cannot get a core does not go faster,
 *   it stretches every clock in every other fork. Measured with the budgets
 *   in place, two runs side by side each time:
 *     - capped, load average 5.1-14.4: both green, 639/639, ~600 s each; the
 *       slowest other test 6.8 s, the F-21 gate 6.8 and 11.1 s;
 *     - uncapped, load average 11.3-16.2: every load-sensitive test green,
 *       ~420 s each; the slowest other test 9.5 s, the F-21 gate 19.9 and
 *       20.7 s.
 *   The machine is shared with other work, so the two are not a controlled
 *   comparison and the wall times say little. What they do say is that the
 *   cap cuts how far a single test stretches by a third to a half, which
 *   is the margin the budgets above depend on. The price is a third of the forks on a
 *   runner that runs the suite alone.
 * - no `retry`, deliberately. A retry turns a broken test green on its second
 *   attempt, and would hide exactly the class of defect F-42 is about.
 *
 * `src/lib/__tests__/the-suite-runs-on-budgets-somebody-chose.test.ts` holds
 * these values, and Testing Library's, in place.
 */
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test-setup.ts'],
      css: false,
      testTimeout: 30_000,
      hookTimeout: 30_000,
      pool: 'forks',
      maxWorkers: '50%',
      retry: 0,
    },
  }),
)
