import { Component, type ErrorInfo, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router'

import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { buttonClasses } from '@/components/buttonStyles'

/**
 * What a customer sees when a screen throws while rendering.
 *
 * Before this existed, the answer was a white page. React unmounts the whole
 * tree when an error escapes render, and with no boundary above the routes
 * that tree is the entire portal: the sidebar, the language switcher and the
 * sign-out button all go with it. A customer's only move is to guess that
 * reloading might help.
 *
 * Three things this deliberately does **not** do:
 *
 *  - It does not show the exception. Not the message, not the stack, not the
 *    component path. A `TypeError` naming an internal symbol tells the
 *    customer nothing and tells anybody reading over their shoulder something
 *    about how the platform is built.
 *  - It does not claim the failure was harmless in general. It says the
 *    specific true thing: *this* error happened while drawing the page, and
 *    drawing a page does not change data. Whatever the customer asked for
 *    before it either happened on the server or did not, and the dashboard and
 *    the activity feed are where they find out.
 *  - It does not invent a reference number. There is no client error
 *    telemetry in this platform, so a reference here would be a string nobody
 *    could look up — worse than none, because the customer would quote it and
 *    be told it means nothing. The route they were on travels to support
 *    instead, which is a fact somebody can act on.
 *
 * Recovery is offered three ways because they fail differently: re-rendering
 * fixes a transient state problem without losing the page, the dashboard
 * escapes a screen that will always throw, and support is the way out when
 * neither works.
 */
interface BoundaryProps {
  children: ReactNode
  /** Re-mounts the boundary when it changes, clearing a caught error. */
  resetKey: string
  fallback: (retry: () => void) => ReactNode
}

interface BoundaryState {
  failed: boolean
}

class ErrorBoundary extends Component<BoundaryProps, BoundaryState> {
  constructor(props: BoundaryProps) {
    super(props)
    this.state = { failed: false }
  }

  static getDerivedStateFromError(): BoundaryState {
    return { failed: true }
  }

  componentDidUpdate(previous: BoundaryProps): void {
    // A caught error is about one screen. Navigating away must not leave the
    // customer looking at the apology for a page they have left.
    if (previous.resetKey !== this.props.resetKey && this.state.failed) {
      this.setState({ failed: false })
    }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    /*
     * The one place the exception is allowed to exist, and only where a
     * developer is watching. There is no client error telemetry in this
     * platform — recorded as an operational gap rather than answered by
     * adding a third-party service in a polish wave — so in production this
     * failure is visible to the customer and invisible to us.
     */
    if (import.meta.env.DEV) {
      console.error('A screen failed while rendering', error, info.componentStack)
    }
  }

  render(): ReactNode {
    if (this.state.failed) {
      return this.props.fallback(() => { this.setState({ failed: false }); })
    }

    return this.props.children
  }
}

/**
 * The boundary as the routes use it: reset by navigation, with the portal's
 * own apology inside it.
 */
export function RouteErrorBoundary({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const location = useLocation()

  return (
    <ErrorBoundary
      resetKey={location.pathname}
      fallback={(retry) => (
        <Card title={t('crash.title')}>
          <p className="text-sm text-[var(--text-secondary)]">{t('crash.body')}</p>

          <p className="mt-2 text-sm text-[var(--text-secondary)]">{t('crash.unchanged')}</p>

          <div className="mt-4 flex flex-wrap gap-2">
            <Button onClick={retry}>{t('crash.retry')}</Button>

            <Link to="/" className={buttonClasses('secondary')}>
              {t('crash.dashboard')}
            </Link>

            {/*
              A link rather than a button, and the route travels with them:
              "which page" is the first thing support asks and the last thing
              a shaken customer remembers.
            */}
            <Link
              to={`/support?about=crash.title&ref=${encodeURIComponent(location.pathname)}`}
              className={buttonClasses('ghost')}
            >
              {t('crash.support')}
            </Link>
          </div>
        </Card>
      )}
    >
      {children}
    </ErrorBoundary>
  )
}
