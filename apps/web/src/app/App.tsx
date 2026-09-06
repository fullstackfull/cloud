import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'

import { LoginPage } from '@/features/auth/LoginPage'
import { useCurrentUser } from '@/features/auth/useAuth'
import { applyLocale, isSupportedLocale } from '@/i18n'
import { ApiError } from '@/lib/api'

const queryClient = new QueryClient({
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

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <LocaleBoundary>
        <Shell />
      </LocaleBoundary>
    </QueryClientProvider>
  )
}

/** Keeps <html lang> and <html dir> in step with the active language. */
function LocaleBoundary({ children }: { children: React.ReactNode }) {
  const { i18n } = useTranslation()

  useEffect(() => {
    const language = i18n.language.split('-')[0] ?? 'en'
    applyLocale(isSupportedLocale(language) ? language : ('en'))
  }, [i18n.language])

  return <>{children}</>
}

function Shell() {
  const { t } = useTranslation()
  const { data: user, isPending } = useCurrentUser()

  if (isPending) {
    return (
      <div className="flex min-h-dvh items-center justify-center text-[var(--text-secondary)]">
        {t('common.loading')}
      </div>
    )
  }

  if (user === null || user === undefined) {
    return <LoginPage />
  }

  return (
    <main className="min-h-dvh bg-[var(--surface)] p-8">
      <h1 className="text-2xl font-semibold text-[var(--text-primary)]">{t('nav.dashboard')}</h1>
      <p className="mt-2 text-sm text-[var(--text-secondary)]">{user.email}</p>
    </main>
  )
}
