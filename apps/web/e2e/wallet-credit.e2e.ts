import { expect, test } from "@playwright/test";

import { fixtures, signIn, users } from "./support/helpers";

/*
 * Paying an invoice from stored credit.
 *
 * The reason this needs a browser: the three figures in the dialogue are the
 * whole of the feature's honesty. A customer who is told "credit available
 * 12.750" and then finds an invoice still open because the credit only covered
 * part of it has been misled by arithmetic, not by a bug in the ledger.
 */

function invoiceRow(page: import("@playwright/test").Page, number: string) {
  return page.getByRole("row").filter({ hasText: number });
}

test.describe("in English", () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer);
  });

  test("credit that covers the invoice says so, and settles it", async ({
    page,
  }) => {
    await page.goto("/invoices");

    // The large invoice first, so this spec's assertions do not depend on how
    // much credit an earlier spec in the file happened to leave behind: 40.000
    // is more than the seeded 12.750 whatever else has run.
    await invoiceRow(page, fixtures.largeInvoice)
      .getByRole("button", { name: /use credit/i })
      .click();

    // The rest stays payable by card, and the dialogue has to say so — "paid"
    // and "partly paid" are different outcomes and only one stops the dunning.
    await expect(
      page.getByText(/does not cover the whole invoice/i),
    ).toBeVisible();
    await expect(page.getByText(/credit available/i)).toBeVisible();
    await expect(page.getByText(/still to pay/i)).toBeVisible();

    await page
      .getByRole("button", { name: /cancel|close/i })
      .first()
      .click();

    // And the invoice the credit does cover: 12.750 seeded against 9.000 owed.
    await invoiceRow(page, fixtures.openInvoice)
      .getByRole("button", { name: /use credit/i })
      .click();
    await expect(
      page.getByText(/does not cover the whole invoice/i),
    ).toHaveCount(0);

    await page
      .getByRole("button", { name: /^use credit$/i })
      .last()
      .click();

    // The invoice leaves the payable state, which is the outcome the customer
    // came for — not a toast saying the request was accepted.
    await expect(
      invoiceRow(page, fixtures.openInvoice).getByRole("button", {
        name: /use credit/i,
      }),
    ).toHaveCount(0);
  });
});

test.describe("Arabic", () => {
  test.use({ locale: "ar" });

  test("the credit dialogue reads in Arabic and keeps Western numerals", async ({
    page,
  }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ });
    await page.goto("/invoices");

    await invoiceRow(page, fixtures.largeInvoice)
      .getByRole("button", { name: "استخدام الرصيد" })
      .click();

    await expect(page.getByText("الرصيد المتاح")).toBeVisible();
    await expect(page.getByText("المتبقّي للدفع")).toBeVisible();

    /*
     * Eastern Arabic numerals are correct Arabic and wrong for money a
     * customer has to reconcile against a bank statement. Matched by shape
     * rather than by a literal amount, because how much credit is left depends
     * on what the specs before this one spent.
     */
    await expect(page.getByText(/\d+\.\d{3}/).first()).toBeVisible();
  });
});
