import { defineConfig, devices } from '@playwright/test'

/*
 * The browser suite.
 *
 * It drives the real portal against the real API against real PostgreSQL and
 * Redis. Nothing here stubs a network call: a test that stubs the API is a
 * test of the component tree, and there are already thirty-eight of those in
 * `src/`. What this suite exists to catch is everything between them — a route
 * guard that lets the wrong person through, a cookie that is not sent because
 * the origins stopped matching, an Arabic page that renders left to right.
 *
 * Two ports of its own, so a suite run cannot collide with a development
 * server somebody left running.
 */

const API_PORT = Number(process.env.E2E_API_PORT ?? 8001)
const WEB_PORT = Number(process.env.E2E_WEB_PORT ?? 5174)

const API_ORIGIN = `http://127.0.0.1:${API_PORT}`
const WEB_ORIGIN = `http://localhost:${WEB_PORT}`

/*
 * The API is served from the control plane with its own database. `APP_ENV` is
 * local rather than testing: the testing environment refuses a fake provider
 * in some paths and, more importantly, a browser suite that ran against a
 * different environment from the one a developer runs would prove less than it
 * appears to.
 *
 * The session cookie is the reason for the SANCTUM_STATEFUL_DOMAINS line. The
 * portal is on one port and the API on another; Vite proxies `/api` and
 * `/sanctum` so the browser only ever sees one origin, and Sanctum has to
 * recognise that origin as stateful or it issues no session at all.
 */
const apiEnvironment = {
  APP_ENV: 'local',
  APP_URL: API_ORIGIN,
  DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'lynomia_e2e',
  FRONTEND_URL: WEB_ORIGIN,
  SANCTUM_STATEFUL_DOMAINS: `localhost:${WEB_PORT},127.0.0.1:${WEB_PORT}`,
  SESSION_DOMAIN: 'localhost',
}

export default defineConfig({
  testDir: './e2e',
  // Named `.e2e.ts` and not `.spec.ts` so that vitest, which owns `src/`,
  // cannot pick these up and try to run a browser suite in jsdom.
  testMatch: '**/*.e2e.ts',

  // One worker. These share a database with fixed fixtures, and two workers
  // signing the same user in and out would produce failures that are about
  // the harness rather than about the platform.
  workers: 1,
  fullyParallel: false,

  // A retry masks a flaky test, and a flaky browser test is usually a real
  // race in the application. Failing once is the signal.
  retries: 0,

  timeout: 30_000,
  expect: { timeout: 10_000 },

  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],

  use: {
    baseURL: WEB_ORIGIN,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        /*
         * An image that ships a browser rather than downloading one sets
         * PLAYWRIGHT_CHROMIUM_EXECUTABLE to it. Left unset — on a developer's
         * machine, and in CI — Playwright uses the build it manages itself,
         * which is the arrangement that keeps the pinned version and the
         * binary in step.
         *
         * Written as an override rather than as a hard-coded path because a
         * path in this file would be a path that only works in one place.
         */
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE !== undefined
          ? { launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } }
          : {}),
      },
    },
  ],

  globalSetup: './e2e/support/global-setup.ts',

  webServer: [
    {
      command: `php artisan serve --host=127.0.0.1 --port=${API_PORT}`,
      cwd: '../control-plane',
      url: `${API_ORIGIN}/sanctum/csrf-cookie`,
      // /sanctum/csrf-cookie answers 204 to anybody, which is what a readiness
      // probe needs: it proves the application booted and is routing without
      // needing a session. /api/v1/me was tried first and answers 401 — a
      // perfectly good signal that Playwright treats as "not ready".
      ignoreHTTPSErrors: true,
      reuseExistingServer: !process.env.CI,
      // Generous, because the first boot on a cold CI runner compiles the whole
      // framework before it can answer anything, and a suite that fails at
      // exactly sixty seconds is reporting its own impatience rather than a
      // defect.
      timeout: 180_000,
      stdout: 'pipe',
      stderr: 'pipe',
      env: apiEnvironment,
    },
    {
      command: `npm run dev -- --port ${WEB_PORT} --strictPort`,
      url: WEB_ORIGIN,
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: { VITE_API_URL: API_ORIGIN },
    },
  ],
})
