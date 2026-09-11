import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { PageHeader } from '@/components/PageHeader'
import { useConsoleSession } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * A text console on one machine.
 *
 * What this page is and is not is worth stating plainly, because the gap is
 * the kind that gets papered over.
 *
 * **Is:** a terminal. It redeems a single-use permit against the console
 * gateway, streams bytes both ways over a WebSocket, and shows them. That is a
 * complete serial console — which is what a customer needs when a machine will
 * not boot far enough to accept SSH.
 *
 * **Is not:** a graphical VNC client. Rendering a framebuffer needs a client
 * like noVNC, which this portal does not carry. A machine whose console speaks
 * the RFB protocol will stream bytes this page cannot draw, and the page says
 * so rather than showing a blank rectangle and letting the customer conclude
 * their server is dead.
 *
 * The permit is requested when the customer presses connect, never on page
 * load: it lives for sixty seconds, and one minted by a tab left open is a
 * permit that has expired before anybody looks.
 */
type ConnectionState = 'idle' | 'requesting' | 'connecting' | 'open' | 'closed' | 'refused' | 'unavailable'

export function ConsolePage() {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const describeError = useApiErrorMessage()

  const session = useConsoleSession()
  const socket = useRef<WebSocket | null>(null)
  const [state, setState] = useState<ConnectionState>('idle')
  const [lines, setLines] = useState<string[]>([])
  const output = useRef<HTMLPreElement>(null)

  const append = useCallback((text: string) => {
    setLines((previous) => {
      const next = [...previous, text]

      // Bounded. A console left open all afternoon would otherwise grow a
      // React state until the tab stops responding.
      return next.length > 500 ? next.slice(next.length - 500) : next
    })
  }, [])

  useEffect(() => {
    output.current?.scrollTo({ top: output.current.scrollHeight })
  }, [lines])

  useEffect(() => () => { socket.current?.close() }, [])

  const connect = () => {
    setLines([])
    setState('requesting')

    session.mutate(
      { id },
      {
        onSuccess: (issued) => {
          if (issued.gateway === null) {
            /*
             * No gateway is configured for this deployment. Said out loud
             * rather than shown as a failed connection: the customer has done
             * nothing wrong and there is nothing they can retry.
             */
            setState('unavailable')

            return
          }

          setState('connecting')

          const url = new URL(issued.gateway)
          url.searchParams.set('session', issued.id)
          url.searchParams.set('token', issued.token)
          url.searchParams.set('machine', issued.virtual_machine_id)

          const ws = new WebSocket(url.toString())
          ws.binaryType = 'arraybuffer'
          socket.current = ws

          ws.onopen = () => { setState('open') }

          ws.onmessage = (event: MessageEvent<ArrayBuffer | string>) => {
            const text =
              typeof event.data === 'string'
                ? event.data
                : new TextDecoder().decode(new Uint8Array(event.data))

            append(text)
          }

          ws.onclose = (event: CloseEvent) => {
            // 1008 is the one code the gateway uses for every refusal: the
            // permit was already spent, or expired, or was not for this
            // machine. The customer's remedy is the same in all three cases.
            setState(event.code === 1008 ? 'refused' : 'closed')
          }

          ws.onerror = () => { setState('closed') }
        },
        onError: () => { setState('idle') },
      },
    )
  }

  const disconnect = () => {
    socket.current?.close()
    socket.current = null
    setState('closed')
  }

  const send = (text: string) => {
    if (socket.current?.readyState === WebSocket.OPEN) {
      socket.current.send(new TextEncoder().encode(text))
    }
  }

  const failure = describeError(session.error)

  return (
    <>
      <PageHeader title={t('console.title')} description={t('console.subtitle')} />

      {failure !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      ) : null}

      {state === 'unavailable' ? (
        <div className="mb-4">
          <Alert tone="warning">{t('console.noGateway')}</Alert>
        </div>
      ) : null}

      {state === 'refused' ? (
        <div className="mb-4">
          <Alert tone="error">{t('console.refused')}</Alert>
        </div>
      ) : null}

      <Card>
        <div className="mb-3 flex items-center gap-2">
          <Button
            onClick={connect}
            loading={state === 'requesting' || state === 'connecting'}
            disabled={state === 'open'}
          >
            {t('console.connect')}
          </Button>

          <Button variant="ghost" onClick={disconnect} disabled={state !== 'open'}>
            {t('console.disconnect')}
          </Button>

          <span className="text-xs text-[var(--text-muted)]">{t(`console.state.${state}`)}</span>
        </div>

        <pre
          ref={output}
          dir="ltr"
          // A terminal is left to right whatever the page is, and monospaced:
          // console output aligned by spaces is unreadable in a proportional
          // font, and mirrored it is worse than unreadable.
          className="technical h-96 overflow-auto whitespace-pre-wrap rounded bg-[var(--surface-sunken)] p-3 text-xs"
          aria-label={t('console.output')}
        >
          {lines.join('')}
        </pre>

        {/*
          Uncontrolled on purpose: a terminal line is submitted and cleared on
          Enter, and routing every keystroke through React state would put a
          re-render between the customer and a console they are typing into.

          The label is hidden rather than absent — the box sits directly under
          a terminal that visibly explains it, but a screen reader reaching the
          control on its own still needs to be told what it is.
        */}
        <div className="mt-3">
          <Field
            label={t('console.input')}
            labelHidden
            dir="ltr"
            className="technical w-full"
            placeholder={t('console.inputPlaceholder')}
            disabled={state !== 'open'}
            onKeyDown={(event) => {
              if (event.key !== 'Enter') return

              const field = event.currentTarget
              send(field.value + '\n')
              field.value = ''
            }}
          />
        </div>

        <p className="mt-3 text-xs text-[var(--text-muted)]">{t('console.textOnly')}</p>
      </Card>
    </>
  )
}
