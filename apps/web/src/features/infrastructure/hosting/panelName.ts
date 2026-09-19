/**
 * What the panel is called, in a way that is always true.
 *
 * The API names cPanel or DirectAdmin when it knows which node the account is
 * on, and says nothing when it does not — no node yet, or a controlled fake.
 * In that case the button says "hosting control panel", which is correct
 * whichever it turns out to be. Naming a panel the platform has not confirmed
 * would be the portal claiming something about infrastructure it cannot see.
 */
export function panelNameKey(panelType: string | null): string {
  switch (panelType) {
    case 'cpanel':
      return 'hosting.panel.cpanel'
    case 'directadmin':
      return 'hosting.panel.directadmin'
    default:
      return 'hosting.panel.generic'
  }
}
