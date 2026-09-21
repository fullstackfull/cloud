import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * The journey a new deployment's first operator actually makes.
 *
 * Proven in PHPUnit: the console bootstrap establishes exactly one privileged
 * operator and then refuses, the whole inventory chain is creatable in order
 * from an empty database, and a management pool never becomes customer
 * capacity. What the browser adds is that a person can do it — that the forms
 * exist, that the parent of each object can be chosen from the one before it,
 * and that what they created is on the screen afterwards.
 *
 * It begins after the first sign-in, because the bootstrap is deliberately a
 * deployment step at a shell and not a page: an authenticated admin screen
 * cannot be the way to create the first person who may use an authenticated
 * admin screen.
 *
 * The estate the seeder already holds is not in the way: everything here is
 * created with names of its own and asserted by those names.
 */

const REGION = 'e2e-browser-region'
const DATACENTER = 'e2e-browser-dc'
const CLUSTER = 'e2e-browser-cluster'
const POOL = 'e2e-browser-pool'

test.describe('a first operator configuring a deployment', () => {
  test('registers a region, a datacenter, a cluster and an address pool, and sees them persist', async ({ page }) => {
    await signIn(page, users.operator)

    // ---- a region: the top of the chain, and the link that was missing ----
    await page.goto('/admin/control-center/sites')
    await expect(page.getByRole('heading', { name: /sites|where machines are/i }).first()).toBeVisible()

    await page.getByRole('button', { name: /register a region/i }).click()

    const regionForm = page.getByRole('form', { name: /register a region/i })
    await regionForm.getByLabel(/logical id/i).fill(REGION)
    await regionForm.getByLabel(/country/i).fill('KW')
    await regionForm.getByLabel(/name \(english\)/i).fill('Browser Region')
    await regionForm.getByRole('button', { name: /register a region/i }).click()
    await expect(regionForm).toBeHidden()

    // ---- a datacenter, choosing the region that now exists ---------------
    await page.getByRole('button', { name: /register a datacenter/i }).click()

    const dcForm = page.getByRole('form', { name: /register a datacenter/i })
    // The dropdown that used to be empty on a fresh deployment.
    await dcForm.getByLabel(/region/i).selectOption({ label: 'Browser Region' })
    await dcForm.getByLabel(/logical id/i).fill(DATACENTER)
    await dcForm.getByLabel(/display name/i).fill('Browser Datacenter')
    await dcForm.getByRole('button', { name: /register a datacenter/i }).click()

    await expect(page.getByRole('listitem', { name: 'Browser Datacenter' })).toBeVisible()

    // ---- a cluster in it -------------------------------------------------
    await page.goto('/admin/control-center/networking')
    await expect(page.getByRole('heading', { name: /networking/i }).first()).toBeVisible()

    await page.getByRole('button', { name: /register a cluster/i }).click()

    const clusterForm = page.getByRole('form', { name: /register a cluster/i })
    await clusterForm.getByLabel(/datacenter/i).selectOption({ label: 'Browser Datacenter' })
    await clusterForm.getByLabel(/logical id/i).fill(CLUSTER)
    await clusterForm.getByLabel(/display name/i).fill('Browser Cluster')

    // Said on the form, before the operator commits to anything: writing a
    // cluster down is not reaching it.
    await expect(clusterForm.getByText(/nothing is contacted/i)).toBeVisible()

    await clusterForm.getByRole('button', { name: /register a cluster/i }).click()

    const cluster = page.getByRole('listitem', { name: 'Browser Cluster' })
    await expect(cluster).toBeVisible()
    await expect(cluster).toContainText(/not contacted yet/i)

    // ---- an address pool, and the distinction that matters ---------------
    await page.getByRole('button', { name: /register an address pool/i }).click()

    const poolForm = page.getByRole('form', { name: /register an address pool/i })
    await poolForm.getByLabel(/datacenter/i).selectOption({ label: 'Browser Datacenter' })
    await poolForm.getByLabel(/logical id/i).fill(POOL)
    await poolForm.getByLabel(/display name/i).fill('Browser Pool')

    // Choosing management warns, in the form, that it will never be customer
    // capacity and that the choice is permanent.
    await poolForm.getByLabel(/scope/i).selectOption('management')
    await expect(poolForm.getByText(/reaches the hypervisor and bmc control planes/i)).toBeVisible()

    await poolForm.getByLabel(/scope/i).selectOption('public')
    await poolForm.getByRole('button', { name: /register an address pool/i }).click()

    const pool = page.getByRole('listitem', { name: POOL })
    await expect(pool).toBeVisible()
    await expect(pool).toContainText(/customer allocatable/i)
  })

  test('can see who operates the platform and is told how a new one gets in', async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/operators')

    await expect(page.getByRole('heading', { name: /operators/i }).first()).toBeVisible()

    // Super Admin is not described as a role with no permissions.
    const superAdmin = page.getByRole('listitem', { name: 'Super Admin' })
    await expect(superAdmin).toContainText(/grants everything/i)

    await page.getByRole('button', { name: /add an operator/i }).click()

    const form = page.getByRole('form', { name: /add an operator/i })
    await expect(form.getByText(/one-time link/i)).toBeVisible()
    await expect(form.getByLabel(/password/i)).toHaveCount(0)
  })
})
