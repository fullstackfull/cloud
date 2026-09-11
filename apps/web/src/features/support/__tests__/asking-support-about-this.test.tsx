import { describe, expect, it } from 'vitest'

import {
  draftBody,
  draftSubject,
  readSupportContext,
  supportPathFor,
} from '@/features/support/supportContext'
import i18n from '@/i18n'

/**
 * AS-14: a way into support that already knows what it is about.
 *
 * Every path into support was the same empty form, so the ticket that arrived
 * said "my server is broken" and support's first reply was three questions.
 * The context travels in the URL — which is what makes the link a link, since
 * it survives a copy, a middle-click and a reload — and the round trip is what
 * this file asserts, along with the one thing a URL must not be trusted for.
 */
describe('asking support about this', () => {
  it('carries what it is about, and reads it back', () => {
    const path = supportPathFor({
      subjectKey: 'activity.vps.reinstalled',
      resource: { kind: 'vps', id: '01JVM', identity: 'web-01' },
      reference: 'INV-000042',
      serviceId: '01JSERVICE',
    })

    expect(path.startsWith('/support?')).toBe(true)

    const read = readSupportContext(new URLSearchParams(path.split('?')[1]))

    expect(read).toEqual({
      subjectKey: 'activity.vps.reinstalled',
      resource: { kind: 'vps', id: '01JVM', identity: 'web-01' },
      reference: 'INV-000042',
      serviceId: '01JSERVICE',
    })
  })

  it('refuses a subject that is not one of ours', () => {
    /*
     * `t()` on a key that does not exist returns the key, so without this
     * check a hand-edited link would put an arbitrary dotted string into the
     * subject line of a support form. Harmless to the platform, confusing to
     * the person reading it — and the same check is what keeps the draft from
     * being assembled out of half a context.
     */
    for (const about of [
      'billing.secretInternalThing',
      '../../etc/passwd',
      'activity.nothing.like.this',
      'attention.',
    ]) {
      expect(readSupportContext(new URLSearchParams({ about }))).toBeNull()
    }
  })

  it('names the resource in the subject, because that is support s first question', () => {
    const subject = draftSubject(
      {
        subjectKey: 'attention.operation.needsReview',
        resource: { kind: 'vps', id: '01JVM', identity: 'web-01' },
      },
      // The real translator, so the assertion is about the sentence a
      // customer would read rather than about a key.
      i18n.t.bind(i18n),
    )

    expect(subject).toContain('Work stopped and we are looking at it')
    expect(subject).toContain('web-01')
  })

  it('leaves the body unfinished, because the customer s own words are the point', () => {
    const body = draftBody(
      {
        subjectKey: 'activity.domain.registered',
        resource: { kind: 'domain', id: '01JDOM', identity: 'lynomia.test' },
        reference: 'REG-9',
      },
      i18n.t.bind(i18n),
    )

    expect(body).toContain('Domain registered')
    expect(body).toContain('lynomia.test')
    expect(body).toContain('REG-9')

    // Ends with room to type. A body prefilled with three paragraphs of
    // template is a body people delete.
    expect(body.endsWith('\n\n')).toBe(true)
  })

  it('omits a service id nobody gave it rather than guessing one', () => {
    /*
     * A resource id is not a service id: a machine's row and the row of the
     * service that pays for it are different things. A caller without the
     * service id leaves it out, and the ticket is attached to nothing rather
     * than to whatever id happened to be to hand.
     */
    const path = supportPathFor({
      subjectKey: 'activity.vps.restarted',
      resource: { kind: 'vps', id: '01JVM', identity: 'web-01' },
    })

    expect(path).not.toContain('service=')

    const read = readSupportContext(new URLSearchParams(path.split('?')[1]))
    expect(read?.serviceId).toBeUndefined()
  })
})
