import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'

import { DashboardPage } from '@/features/account/DashboardPage'
import { AdminCustomersPage } from '@/features/admin/AdminCustomersPage'
import { AdminInfrastructurePage } from '@/features/admin/AdminInfrastructurePage'
import { AdminPaymentsPage } from '@/features/admin/AdminPaymentsPage'
import { AdminProvisioningPage } from '@/features/admin/AdminProvisioningPage'
import { InvoicesPage } from '@/features/billing/InvoicesPage'
import { SubscriptionsPage } from '@/features/billing/SubscriptionsPage'
import { CataloguePage } from '@/features/catalog/CataloguePage'
import { ProductPage } from '@/features/catalog/ProductPage'
import { DedicatedPage } from '@/features/infrastructure/DedicatedPage'
import { HostingPage } from '@/features/infrastructure/HostingPage'
import { IpAddressesPage } from '@/features/infrastructure/IpAddressesPage'
import { BackupsPage } from '@/features/backups/BackupsPage'
import { VpsPage } from '@/features/infrastructure/VpsPage'
import { OrderDetailPage } from '@/features/orders/OrderDetailPage'
import { OrdersPage } from '@/features/orders/OrdersPage'
import { ServicesPage } from '@/features/services/ServicesPage'
import { ApiTokensPage } from '@/features/tokens/ApiTokensPage'
import { WalletPage } from '@/features/wallet/WalletPage'
import { ProfilePage } from '@/features/account/ProfilePage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage'
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
            <Route element={<RequireGuest />}>
              <Route element={<PublicLayout />}>
                <Route path="/sign-in" element={<LoginPage />} />
                <Route path="/register" element={<RegisterPage />} />
                <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                <Route path="/reset-password" element={<ResetPasswordPage />} />
              </Route>
            </Route>

            <Route element={<RequireAuth />}>
              <Route element={<AppLayout />}>
                <Route index element={<DashboardPage />} />

                <Route path="/catalogue" element={<CataloguePage />} />
                <Route path="/catalogue/:slug" element={<ProductPage />} />

                <Route path="/orders" element={<OrdersPage />} />
                <Route path="/orders/:id" element={<OrderDetailPage />} />
                <Route path="/invoices" element={<InvoicesPage />} />
                <Route path="/subscriptions" element={<SubscriptionsPage />} />
                <Route path="/wallet" element={<WalletPage />} />

                <Route path="/services" element={<ServicesPage />} />
                <Route path="/vps" element={<VpsPage />} />
                <Route path="/backups" element={<BackupsPage />} />
                <Route path="/dedicated" element={<DedicatedPage />} />
                <Route path="/hosting" element={<HostingPage />} />
                <Route path="/ips" element={<IpAddressesPage />} />

                <Route path="/profile" element={<ProfilePage />} />
                <Route path="/security" element={<SecurityPage />} />
                <Route path="/api-tokens" element={<ApiTokensPage />} />

                {/*
                  The operator area. Gated for presentation by RequireOperator
                  and enforced server-side by a permission on every endpoint —
                  a customer who types the URL sees an empty shell and gets a
                  403 from every request it makes.
                */}
                <Route element={<RequireOperator />}>
                  <Route path="/admin/customers" element={<AdminCustomersPage />} />
                  <Route path="/admin/provisioning" element={<AdminProvisioningPage />} />
                  <Route path="/admin/infrastructure" element={<AdminInfrastructurePage />} />
                  <Route path="/admin/payments" element={<AdminPaymentsPage />} />
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
