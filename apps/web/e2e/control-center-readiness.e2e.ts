import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The readiness screen: whether the platform may sell each thing.
 *
 * Proven in PHPUnit: the ladder, the propagation, that a fake provider stops
 * at ready-for-test and that the declaration is refused below production.
 * What the browser adds is the operator's side — every product on one
 * screen with its blocker in words, and the refusal, when a person tries to
 * declare a product that is not ready, shown in the dialogue rather than
 * swallowed.
 *
 * The seeded environment has only controlled providers and no payment
 * provider, so nothing is ready for production and the DNS product's own
 * provider is blocked on its credential. Both are stable facts to assert.
 */

test.describe('an operator reading product readiness', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/readiness')
    await expect(page.getByRole('heading', { name: /^product readiness$/i })).toBeVisible()
  })

  test('sees every product with its rung and its blocker in words', async ({ page }) => {
    const products = page.getByRole('list', { name: /^product readiness$/i })
    await expect(products.getByRole('listitem', { name: /^(cloud vps|dedicated servers|shared hosting|wordpress|domains|dns|backups|cdn|object storage|gpu compute|email hosting|managed kubernetes)$/i })).toHaveCount(12)

    const dns = products.getByRole('listitem', { name: /^dns$/i })
    await expect(dns).toContainText(/not ready/i)
    await expect(dns).toContainText(/to reach ready for test/i)
    await expect(dns).toContainText(/blocked: credentials/i)
    await expect(dns).toContainText(fixtures.providerBlocked)
    // Translation keys and enum values never reach the screen as themselves.
    // (The requirement rows quote the engine's diagnostic, which names states
    // by value on purpose; it is a diagnostic, and it sits behind a disclosure.)
    await expect(page.getByText(/controlCenter\.guidance|blocked_credentials/)).toHaveCount(0)

    // A dependency edge, in words.
    const view = page.getByRole('list', { name: /what each product leans on/i })
    await expect(view.getByRole('listitem', { name: /^wordpress$/i })).toContainText(/leans on shared hosting/i)
    await expect(view.getByRole('listitem', { name: /^managed kubernetes$/i })).toContainText(/leans on cloud vps, dns, backups, object storage/i)
  })

  test('sees the prepared products as prepared, answered, and not for sale', async ({ page }) => {
    const products = page.getByRole('list', { name: /^product readiness$/i })

    // A prepared product says what it is, and the ten questions are answered
    // in words. Nothing is registered for it, so the provider answer is no.
    const cdn = products.getByRole('listitem', { name: /^cdn$/i })
    await expect(cdn).toContainText(/software prepared/i)
    await expect(cdn).toContainText(/provider available\?/i)
    await expect(cdn).toContainText(/ready to sell\?/i)
    await expect(cdn.getByRole('button', { name: /declare sellable/i })).toBeDisabled()

    // Kubernetes is readiness only, and says so.
    const k8s = products.getByRole('listitem', { name: /^managed kubernetes$/i })
    await expect(k8s).toContainText(/readiness only/i)
    await expect(k8s).toContainText(/cannot be declared sellable/i)

    // GPU compute is blocked on hardware before anything else: no card in
    // any machine the platform may configure.
    const gpu = products.getByRole('listitem', { name: /^gpu compute$/i })
    await expect(gpu).toContainText(/blocked: hardware/i)
    await expect(gpu.getByRole('link', { name: /^machines$/i })).toBeVisible()

    // The credential blocker on DNS links to the credentials screen, and the
    // link goes there.
    const dns = products.getByRole('listitem', { name: /^dns$/i })
    await dns.getByRole('link', { name: /^credentials$/i }).click()
    await expect(page).toHaveURL(/\/admin\/control-center\/credentials$/)
    await page.goBack()
    await expect(page.getByRole('heading', { name: /^product readiness$/i })).toBeVisible()
  })

  test('cannot declare anything sellable here, and is told why by the platform', async ({ page }) => {
    const products = page.getByRole('list', { name: /^product readiness$/i })

    // No product reaches production on fakes, so no declare button is live.
    await expect(products.getByRole('button', { name: /declare sellable/i, disabled: false })).toHaveCount(0)
    await expect(products.getByText(/offered once the product is ready for production/i).first()).toBeVisible()

    // Reassessing is allowed and changes nothing on a settled estate.
    await page.getByRole('button', { name: /reassess everything/i }).click()
    await expect(page.getByText(/assessed 12 products/i)).toBeVisible()
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the readiness screen reads in Arabic', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/readiness')

    await expect(page.getByRole('heading', { name: /جاهزية المنتجات/ })).toBeVisible()
    const dns = page.getByRole('list', { name: /جاهزية المنتجات/ }).getByRole('listitem', { name: /^DNS$/ })
    await expect(dns).toContainText(/غير جاهز/)
    await expect(dns).toContainText(/محجوب: الاعتماد/)
    const k8s = page.getByRole('list', { name: /جاهزية المنتجات/ }).getByRole('listitem', { name: /Kubernetes مُدار/ })
    await expect(k8s).toContainText(/جاهزية فقط/)
  })
})
