import { execFileSync } from 'node:child_process'
import { rmSync } from 'node:fs'
import path from 'node:path'

import {
  FAKE_COMPUTE_STATE_PATH,
  FAKE_PAYMENTS_STATE_PATH,
  MAIL_OUTBOX_PATH,
} from '../../playwright.config'

/*
 * A clean database before the suite, and the same one every time.
 *
 * `migrate:fresh` rather than a transaction per test: these tests drive a
 * browser against a server in another process, so there is no transaction to
 * roll back. Determinism comes from rebuilding rather than from isolation.
 *
 * It refuses to run against anything but the E2E database. The command it runs
 * drops every table, and the difference between the E2E database and a
 * developer's own is one environment variable.
 */
export default function globalSetup(): void {
  const database = process.env.E2E_DB_DATABASE ?? 'lynomia_e2e'

  if (!database.includes('e2e')) {
    throw new Error(
      `Refusing to rebuild "${database}": the browser suite drops every table, and this ` +
        'does not look like the E2E database. Set E2E_DB_DATABASE to one whose name says so.',
    )
  }

  const controlPlane = path.resolve(import.meta.dirname, '../../../control-plane')

  /*
   * The fake hypervisor's fleet file, shared with the API process. Removed
   * first so a run starts with the fleet the seeder writes and nothing a
   * previous run left behind.
   */
  const fleet = process.env.COMPUTE_FAKE_STATE_PATH ?? FAKE_COMPUTE_STATE_PATH
  rmSync(fleet, { force: true })

  /*
   * Last run's mail and last run's payment decisions, gone.
   *
   * Both are files the suite reads: a leftover verification link from the
   * previous run would be followed instead of this run's, and a leftover
   * approval would make a fresh payment look already authorised. Deleting
   * them here rather than in a spec keeps one run independent of the last.
   */
  rmSync(process.env.MAIL_OUTBOX_PATH ?? MAIL_OUTBOX_PATH, { force: true })
  rmSync(process.env.PAYMENTS_FAKE_STATE_PATH ?? FAKE_PAYMENTS_STATE_PATH, { force: true })

  const artisan = (args: string[]): void => {
    execFileSync('php', ['artisan', ...args], {
      cwd: controlPlane,
      stdio: 'inherit',
      env: { ...process.env, APP_ENV: 'local', DB_DATABASE: database, COMPUTE_FAKE_STATE_PATH: fleet },
    })
  }

  artisan(['migrate:fresh', '--force', '--seed', '--no-interaction'])
  artisan(['db:seed', '--class=Database\\Seeders\\E2ESeeder', '--force', '--no-interaction'])
}
