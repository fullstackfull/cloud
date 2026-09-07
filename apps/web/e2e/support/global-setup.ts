import { execFileSync } from 'node:child_process'
import path from 'node:path'

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

  const artisan = (args: string[]): void => {
    execFileSync('php', ['artisan', ...args], {
      cwd: controlPlane,
      stdio: 'inherit',
      env: { ...process.env, APP_ENV: 'local', DB_DATABASE: database },
    })
  }

  artisan(['migrate:fresh', '--force', '--seed', '--no-interaction'])
  artisan(['db:seed', '--class=Database\\Seeders\\E2ESeeder', '--force', '--no-interaction'])
}
