import { readFileSync } from 'node:fs'

import { MAIL_OUTBOX_PATH } from '../../playwright.config'

/**
 * The mail the platform actually sent, read from the test outbox.
 *
 * This exists so the registration journey can follow the real verification
 * link out of the real message. The alternatives are all weaker: writing
 * `email_verified_at` proves a column can be written and would pass with a
 * broken signed URL, and building the link in the spec proves the endpoint
 * works while saying nothing about whether the product ever sends one.
 *
 * The transport behind it (OutboxTransport) refuses to be constructed in
 * production, and nothing in the application reads this file — the harness
 * does.
 */
export interface OutboxMessage {
  to: string[]
  subject: string
  text: string
  html: string
  sent_at: string
}

function outboxPath(): string {
  return process.env.MAIL_OUTBOX_PATH ?? MAIL_OUTBOX_PATH
}

export function readOutbox(): OutboxMessage[] {
  let raw = ''

  try {
    raw = readFileSync(outboxPath(), 'utf8')
  } catch {
    // No mail has been sent yet in this run.
    return []
  }

  return raw
    .split('\n')
    .filter((line) => line.trim() !== '')
    .map((line) => JSON.parse(line) as OutboxMessage)
}

/**
 * The newest message sent to an address, or null while none has arrived.
 *
 * Newest rather than first: the resend button exists, and a customer who
 * pressed it is meant to be able to use the second link.
 */
export function latestMessageTo(address: string): OutboxMessage | null {
  const matching = readOutbox().filter((message) =>
    message.to.some((recipient) => recipient.toLowerCase() === address.toLowerCase()),
  )

  return matching.length === 0 ? null : (matching[matching.length - 1] ?? null)
}

/**
 * Waits for a message to that address and returns the first link in it.
 *
 * Polled rather than awaited on a promise, because the mail is written by the
 * API process — the whole point of going through a file is that the two
 * processes are genuinely separate.
 */
export async function linkFromMailTo(
  address: string,
  options: { timeoutMs?: number; matching?: RegExp } = {},
): Promise<string> {
  const deadline = Date.now() + (options.timeoutMs ?? 10_000)
  const wanted = options.matching ?? /https?:\/\/\S+/

  for (;;) {
    const message = latestMessageTo(address)

    if (message !== null) {
      const body = `${message.text}\n${message.html}`
      const found = wanted.exec(body)

      if (found !== null) {
        // Mail bodies wrap and escape; trim the punctuation a wrapped URL
        // picks up rather than following a link with a bracket on the end.
        return found[0].replace(/[)\]>"'.,]+$/, '')
      }
    }

    if (Date.now() > deadline) {
      throw new Error(
        `No mail with a link arrived for ${address} within the timeout. ` +
          `Outbox holds ${readOutbox().length} message(s).`,
      )
    }

    await new Promise((resolve) => setTimeout(resolve, 250))
  }
}
