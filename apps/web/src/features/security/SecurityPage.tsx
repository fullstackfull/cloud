import { useTranslation } from 'react-i18next'

import { PageHeader } from '@/components/PageHeader'

import { LoginActivitySection } from './LoginActivitySection'
import { PasswordSection } from './PasswordSection'
import { SessionsSection } from './SessionsSection'
import { TwoFactorSection } from './TwoFactorSection'

export function SecurityPage() {
  const { t } = useTranslation()

  return (
    <>
      <PageHeader title={t('nav.security')} description={t('security.subtitle')} />

      <div className="flex flex-col gap-6">
        <TwoFactorSection />
        <PasswordSection />
        <SessionsSection />
        <LoginActivitySection />
      </div>
    </>
  )
}
