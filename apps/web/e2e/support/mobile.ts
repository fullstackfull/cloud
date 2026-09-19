import { expect, type Locator, type Page } from '@playwright/test'

/** Opens the phone drawer and returns it, asserting the hamburger and the dialog agree. */
export async function openMenu(page: Page, name: RegExp = /^menu$/i): Promise<Locator> {
  const button = page.getByRole('button', { name })
  await expect(button).toBeVisible()
  await button.click()
  const drawer = page.getByRole('dialog', { name })
  await expect(drawer).toBeVisible()

  return drawer
}
