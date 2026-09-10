import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'

import { DashboardPage } from '@/features/account/DashboardPage'
import { AdminAccountChangesPage } from '@/features/admin/AdminAccountChangesPage'
import { AdminCustomersPage } from '@/features/admin/AdminCustomersPage'
import { AdminDriftPage } from '@/features/admin/AdminDriftPage'
import { AdminSupportPage } from '@/features/admin/AdminSupportPage'
import { AdminInfrastructurePage } from '@/features/admin/AdminInfrastructurePage'
import { AdminOperationsPage } from '@/features/admin/AdminOperationsPage'
import { AdminPaymentsPage } from '@/features/admin/AdminPaymentsPage'
import { AdminProvisioningPage } from '@/features/admin/AdminProvisioningPage'
import { CredentialsPage } from '@/features/controlCenter/CredentialsPage'
import { DeploymentsPage } from '@/features/controlCenter/DeploymentsPage'
import { DiscoveryPage } from '@/features/controlCenter/DiscoveryPage'
import { LicencesPage } from '@/features/controlCenter/LicencesPage'
import { OverviewPage } from '@/features/controlCenter/OverviewPage'
import { PlansPage } from '@/features/controlCenter/PlansPage'
import { ProvidersPage } from '@/features/controlCenter/ProvidersPage'
import { ReadinessPage } from '@/features/controlCenter/ReadinessPage'
import { ServersPage } from '@/features/controlCenter/ServersPage'
import { SitesPage } from '@/features/controlCenter/SitesPage'
import { InvoicesPage } from '@/features/billing/InvoicesPage'
import { SubscriptionsPage } from '@/features/billing/SubscriptionsPage'
import { CataloguePage } from '@/features/catalog/CataloguePage'
import { ProductPage } from '@/features/catalog/ProductPage'
import {
  DedicatedActivitySection,
  DedicatedBillingSection,
  DedicatedDangerSection,
  DedicatedDetailPage,
  DedicatedOverviewSection,
} from '@/features/infrastructure/DedicatedDetailPage'
import { DedicatedPage } from '@/features/infrastructure/DedicatedPage'
import {
  HostingActivitySection,
  HostingBillingSection,
  HostingDetailPage,
  HostingOverviewSection,
} from '@/features/infrastructure/HostingDetailPage'
import { HostingPage } from '@/features/infrastructure/HostingPage'
import { IpAddressesPage } from '@/features/infrastructure/IpAddressesPage'
import { NotificationsPage } from '@/features/notifications/NotificationsPage'
import { BackupsPage } from '@/features/backups/BackupsPage'
import { DnsPage } from '@/features/dns/DnsPage'
import {
  DnsZoneDangerSection,
  DnsZoneDetailPage,
  DnsZoneOverviewSection,
  DnsZoneRecordsSection,
  DnsZoneTransferSection,
} from '@/features/dns/DnsZoneDetailPage'
import {
  DomainContactsSection,
  DomainDetailPage,
  DomainNameserversSection,
  DomainOverviewSection,
  DomainTransferSection,
} from '@/features/domains/DomainDetailPage'
import { DomainsPage } from '@/features/domains/DomainsPage'
import {
  WordPressActivitySection,
  WordPressBillingSection,
  WordPressCopiesSection,
  WordPressDetailPage,
  WordPressOverviewSection,
} from '@/features/wordpress/WordPressDetailPage'
import { WordPressPage } from '@/features/wordpress/WordPressPage'
import { PlanChangePage } from '@/features/billing/PlanChangePage'
import { ConsolePage } from '@/features/console/ConsolePage'
import {
  VpsActivitySection,
  VpsBackupsSection,
  VpsBillingSection,
  VpsDangerSection,
  VpsDetailPage,
  VpsNetworkingSection,
  VpsOverviewSection,
} from '@/features/infrastructure/VpsDetailPage'
import { VpsPage } from '@/features/infrastructure/VpsPage'
import { OrderDetailPage } from '@/features/orders/OrderDetailPage'
import { OrdersPage } from '@/features/orders/OrdersPage'
import { ServicesPage } from '@/features/services/ServicesPage'
import { SupportPage } from '@/features/support/SupportPage'
import { InvitationPage } from '@/features/team/InvitationPage'
import { TeamPage } from '@/features/team/TeamPage'
import { ApiTokensPage } from '@/features/tokens/ApiTokensPage'
import { WalletPage } from '@/features/wallet/WalletPage'
import { ProfilePage } from '@/features/account/ProfilePage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage'
import { VerifyEmailPage } from '@/features/auth/VerifyEmailPage'
import { InvoiceDetailPage } from '@/features/billing/InvoiceDetailPage'
import { InvoicePrintPage } from '@/features/billing/InvoicePrintPage'
import { ControlledGatewayPage } from '@/features/payments/ControlledGatewayPage'
import { PaymentsPage } from '@/features/payments/PaymentsPage'
import { SecurityPage } from '@/features/security/SecurityPage'
import { applyLocale, isSupportedLocale } from '@/i18n'
import { ApiError } from '@/lib/api'

import { AppLayout } from './AppLayout'
import { NotFoundPage } from './NotFoundPage'
import { PublicLayout } from './PublicLayout'
import { RequireAuth, RequireGuest, RequireOperator } from './guards'

function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          // Client errors will not become correct by being repeated.
          if (error instanceof ApiError && error.status < 500) return false
          return failureCount < 2
        },
      },
    },
  })
}

export function App() {
  /*
   * One cache per mounted application rather than one per module.
   *
   * A module-level client is shared by every instance in the process, which in
   * the browser is invisible — there is only ever one — but means a signed-out
   * result cached by one instance is served to the next. The lazy initialiser
   * keeps it stable across re-renders while giving each mount its own cache.
   */
  const [queryClient] = useState(createQueryClient)

  return (
    <QueryClientProvider client={queryClient}>
      <LocaleBoundary>
        <BrowserRouter>
          <Routes>
            {/*
              Where a verification link lands.
              
              Not behind RequireGuest and not behind RequireAuth: the customer
              may have clicked the link in a different browser from the one
              they registered in, and they may already be signed in. The page
              itself decides what to offer.
            */}
            <Route element={<PublicLayout />}>
              <Route path="/verify-email" element={<VerifyEmailPage />} />
            </Route>

            <Route element={<RequireGuest />}>
              <Route element={<PublicLayout />}>
                <Route path="/sign-in" element={<LoginPage />} />
                <Route path="/register" element={<RegisterPage />} />
                <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                <Route path="/reset-password" element={<ResetPasswordPage />} />
              </Route>
            </Route>

            {/*
              The printable invoice, authenticated but without the application
              chrome: a printed page should carry the document and not the
              navigation. It is a sibling of the layout rather than a child of
              it for exactly that reason.
            */}
            <Route element={<RequireAuth />}>
              <Route path="/invoices/:id/print" element={<InvoicePrintPage />} />
            </Route>

            <Route element={<RequireAuth />}>
              <Route element={<AppLayout />}>
                <Route index element={<DashboardPage />} />

                <Route path="/catalogue" element={<CataloguePage />} />
                <Route path="/catalogue/:slug" element={<ProductPage />} />

                <Route path="/orders" element={<OrdersPage />} />
                <Route path="/orders/:id" element={<OrderDetailPage />} />
                <Route path="/invoices" element={<InvoicesPage />} />
                <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
                <Route path="/payments" element={<PaymentsPage />} />
                <Route path="/subscriptions" element={<SubscriptionsPage />} />
                <Route path="/subscriptions/:id/plan" element={<PlanChangePage />} />
                <Route path="/wallet" element={<WalletPage />} />

                {/*
                  The fake provider's payment page. The endpoints behind it are
                  refused in production and whenever a real provider is
                  configured, so in a real deployment this route renders a
                  refusal rather than a way to authorise anything.
                */}
                <Route
                  path="/fake-gateway/authorise/:reference"
                  element={<ControlledGatewayPage />}
                />

                <Route path="/services" element={<ServicesPage />} />

                {/*
                  One place per resource (Wave 3).

                  Each family has an index and a page per thing, and the
                  sections of that page are child routes rather than tabs
                  inside a component: a customer sends "the backups of web-01"
                  to a colleague, refreshes it and bookmarks it, and gets the
                  browser's own history and the accessible current-page state
                  for free. Every index path is unchanged, so nothing anybody
                  bookmarked before this wave has moved.

                  The console keeps its own route above the machine's page. It
                  is a full-screen terminal rather than a section of a
                  document, and it was reachable before this wave: nothing
                  about it changes.
                */}
                <Route path="/vps" element={<VpsPage />} />
                <Route path="/vps/:id/console" element={<ConsolePage />} />
                <Route path="/vps/:id" element={<VpsDetailPage />}>
                  <Route index element={<VpsOverviewSection />} />
                  <Route path="networking" element={<VpsNetworkingSection />} />
                  <Route path="backups" element={<VpsBackupsSection />} />
                  <Route path="activity" element={<VpsActivitySection />} />
                  <Route path="billing" element={<VpsBillingSection />} />
                  <Route path="danger" element={<VpsDangerSection />} />
                </Route>

                <Route path="/backups" element={<BackupsPage />} />
                <Route path="/notifications" element={<NotificationsPage />} />

                <Route path="/dedicated" element={<DedicatedPage />} />
                <Route path="/dedicated/:id" element={<DedicatedDetailPage />}>
                  <Route index element={<DedicatedOverviewSection />} />
                  <Route path="activity" element={<DedicatedActivitySection />} />
                  <Route path="billing" element={<DedicatedBillingSection />} />
                  <Route path="danger" element={<DedicatedDangerSection />} />
                </Route>

                <Route path="/hosting" element={<HostingPage />} />
                <Route path="/hosting/:id" element={<HostingDetailPage />}>
                  <Route index element={<HostingOverviewSection />} />
                  <Route path="activity" element={<HostingActivitySection />} />
                  <Route path="billing" element={<HostingBillingSection />} />
                </Route>

                <Route path="/ips" element={<IpAddressesPage />} />

                <Route path="/dns" element={<DnsPage />} />
                {/*
                  Addressed by the zone's name — `/dns/example.com` — which is
                  what a customer recognises. The API accepts a name or an id
                  and resolves either through the acting customer, so a name
                  somebody else holds is not found rather than refused.
                */}
                <Route path="/dns/:identity" element={<DnsZoneDetailPage />}>
                  <Route index element={<DnsZoneOverviewSection />} />
                  <Route path="records" element={<DnsZoneRecordsSection />} />
                  <Route path="transfer" element={<DnsZoneTransferSection />} />
                  <Route path="danger" element={<DnsZoneDangerSection />} />
                </Route>

                <Route path="/domains" element={<DomainsPage />} />
                <Route path="/domains/:identity" element={<DomainDetailPage />}>
                  <Route index element={<DomainOverviewSection />} />
                  <Route path="nameservers" element={<DomainNameserversSection />} />
                  <Route path="contacts" element={<DomainContactsSection />} />
                  <Route path="transfer" element={<DomainTransferSection />} />
                </Route>

                <Route path="/wordpress" element={<WordPressPage />} />
                <Route path="/wordpress/:id" element={<WordPressDetailPage />}>
                  <Route index element={<WordPressOverviewSection />} />
                  <Route path="copies" element={<WordPressCopiesSection />} />
                  <Route path="activity" element={<WordPressActivitySection />} />
                  <Route path="billing" element={<WordPressBillingSection />} />
                </Route>

                <Route path="/profile" element={<ProfilePage />} />
                <Route path="/security" element={<SecurityPage />} />
                <Route path="/api-tokens" element={<ApiTokensPage />} />
                <Route path="/support" element={<SupportPage />} />
                <Route path="/settings/team" element={<TeamPage />} />
                {/*
                  The invitee's landing page. Authenticated like everything
                  else here, but deliberately not scoped to an account: the
                  person arriving may belong to none yet, which is the whole
                  point of the page.
                */}
                <Route path="/invitations/:token" element={<InvitationPage />} />

                {/*
                  The operator area. Gated for presentation by RequireOperator
                  and enforced server-side by a permission on every endpoint —
                  a customer who types the URL sees an empty shell and gets a
                  403 from every request it makes.
                */}
                <Route element={<RequireOperator />}>
                  <Route path="/admin/customers" element={<AdminCustomersPage />} />
                  <Route path="/admin/account-changes" element={<AdminAccountChangesPage />} />
                  <Route path="/admin/provisioning" element={<AdminProvisioningPage />} />
                  <Route path="/admin/operations" element={<AdminOperationsPage />} />
                  <Route path="/admin/drift" element={<AdminDriftPage />} />
                  <Route path="/admin/support" element={<AdminSupportPage />} />
                  <Route path="/admin/infrastructure" element={<AdminInfrastructurePage />} />
                  <Route path="/admin/payments" element={<AdminPaymentsPage />} />
                  <Route path="/admin/control-center" element={<OverviewPage />} />
                  <Route path="/admin/control-center/sites" element={<SitesPage />} />
                  <Route path="/admin/control-center/credentials" element={<CredentialsPage />} />
                  <Route path="/admin/control-center/licences" element={<LicencesPage />} />
                  <Route path="/admin/control-center/machines" element={<ServersPage />} />
                  <Route path="/admin/control-center/providers" element={<ProvidersPage />} />
                  <Route path="/admin/control-center/discovery" element={<DiscoveryPage />} />
                  <Route path="/admin/control-center/readiness" element={<ReadinessPage />} />
                  <Route path="/admin/control-center/plans" element={<PlansPage />} />
                  <Route path="/admin/control-center/deployments" element={<DeploymentsPage />} />
                </Route>
              </Route>
            </Route>

            {/* Legacy path kept so an old bookmark still lands somewhere sensible. */}
            <Route path="/login" element={<Navigate to="/sign-in" replace />} />
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </BrowserRouter>
      </LocaleBoundary>
    </QueryClientProvider>
  )
}

/** Keeps <html lang> and <html dir> in step with the active language. */
function LocaleBoundary({ children }: { children: React.ReactNode }) {
  const { i18n } = useTranslation()

  useEffect(() => {
    const language = i18n.language.split('-')[0] ?? 'en'
    applyLocale(isSupportedLocale(language) ? language : 'en')
  }, [i18n.language])

  return <>{children}</>
}
