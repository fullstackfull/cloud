import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import type { WordPressSite } from '@/lib/types'

/**
 * The four things that have to be true about a WordPress site.
 *
 * A site is a hosting account, a name pointed at it, a certificate, and an
 * installation that answers. Each finishes minutes or days apart, and the
 * tempting design — one spinner labelled "setting up" — is the one that fills
 * the support queue: every customer waiting on a different step asks the same
 * question and the screen has told none of them anything.
 *
 * The last step is the only one that is this platform's own observation: it
 * fetched the site and WordPress answered. The other three are reports from
 * elsewhere — the panel's, the installer's, the certificate authority's — and
 * all three can be true over a site that serves a database error.
 *
 * One component for the index and for the site's own page, so the two cannot
 * disagree about what "live" means.
 */
export function SiteSteps({ site }: { site: WordPressSite }) {
  const { t } = useTranslation()

  return (
    <ul className="flex flex-col gap-1.5 text-sm">
      <Step done={site.dns_ready} label={t('wordpress.steps.dns')} />
      <Step done={site.installed} label={t('wordpress.steps.installed')} />
      <Step done={site.ssl_status === 'active'} label={t('wordpress.steps.certificate')} />
      <Step done={site.is_verified} label={t('wordpress.steps.verified')} />
    </ul>
  )
}

/**
 * What to do about where the site has got to.
 *
 * The single most useful sentence on the screen for a customer waiting on
 * `awaiting_dns`: they are waiting on themselves, and a spinner would never
 * tell them that.
 */
export function SiteAdvice({ site }: { site: WordPressSite }) {
  const { t } = useTranslation()

  if (site.needs_attention) {
    return <Alert tone="warning">{site.failure_reason ?? t('wordpress.needsAttention')}</Alert>
  }

  if (site.state === 'awaiting_dns') {
    return <Alert tone="info">{t('wordpress.awaitingDnsHint')}</Alert>
  }

  if (site.state === 'awaiting_certificate') {
    return <Alert tone="info">{t('wordpress.awaitingCertificateHint')}</Alert>
  }

  return null
}

function Step({ done, label }: { done: boolean; label: string }) {
  return (
    <li className="flex items-center gap-2">
      <span
        aria-hidden
        className={done ? 'text-emerald-600 dark:text-emerald-400' : 'text-[var(--text-muted)]'}
      >
        {done ? '●' : '○'}
      </span>
      <span className={done ? '' : 'text-[var(--text-muted)]'}>{label}</span>
    </li>
  )
}
