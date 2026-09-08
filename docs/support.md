# Support

A ticket is one conversation between an account and the support team. It
belongs to the **customer**, not to the person who opened it, for the same
reason invoices and services do: a colleague has to be able to pick it up when
the person who raised it is on leave.

## Three tables, not four

The obvious model puts a *conversation* between the ticket and its messages. A
ticket **is** the conversation — there is no product in which one ticket holds
two separate threads — and a table with exactly one row per parent is a join
every query pays for and nothing uses. If ticket merging is ever built, that is
when a thread becomes a thing of its own.

```text
support_tickets ──< support_messages ──< support_attachments
```

## Status, and who is waiting

| Status | Means |
| --- | --- |
| `open` | Nobody has answered it yet. This is what a first-response target is measured against. |
| `waiting_for_support` | A conversation under way in which the customer has said something else. |
| `waiting_for_customer` | The team has answered and is waiting. |
| `resolved` | The team believes it is solved. The customer has not agreed yet. |
| `closed` | Finished. Nothing more may be written by either side. |

`open` and `waiting_for_support` look alike and are not: collapsing them hides
the tickets nobody has touched inside the ones somebody is already handling.

**The status follows from who spoke.** A customer's reply makes it the team's
turn; an operator's reply makes it the customer's. Nobody sets it by hand,
because a queue whose state is set by hand fills up with tickets sitting in
"waiting for customer" that the customer answered last week.

**An internal note moves nothing.** It is a message between colleagues; the
customer is still waiting for the same answer, and a note that flipped the
ticket would silently stop the clock on a reply nobody sent. It also does not
stamp `first_responded_at`, so a team cannot post a note to itself and record
that as having answered.

**A reply to a resolved ticket reopens it**, with its history and a bumped
`reopened_count` — a ticket resolved and reopened four times was never fixed,
and that is worth seeing without reading the thread. **A closed ticket
refuses.** Closing is the account saying it is finished; a thread that can
always be revived is a thread that never ends.

## Who may do what

| Act | Customer | Operator |
| --- | --- | --- |
| Open | `support.manage` on any customer role, read-only included | — |
| Reply | yes | `ticket.reply` |
| Internal note | never sees one | `ticket.reply` |
| Close | yes | `ticket.manage` |
| Resolve | **no** | `ticket.manage` |
| Reopen | by replying | `ticket.manage` |
| Assign, re-prioritise | no | `ticket.manage` |
| Read the queue | own tickets only | `ticket.view_any` |

**A customer may close and may not resolve.** Resolved is the team's opinion
that the problem is solved; closed is the account saying it is finished with
the conversation. They are different statements and only one is the customer's
to make.

**Priority is asymmetric.** A customer chooses `low`, `normal` or `high`;
`urgent` is an operator's judgement. Urgent is what pages somebody out of
hours, and a priority that can be self-selected stops meaning anything within a
month — at which point the team learns to ignore it, which is worse than not
having it.

**A read-only member may open a ticket.** Read-only is about the account's
resources and its money. Somebody who can see a broken server and cannot report
it is not read-only, they are stranded.

## Attachments

Every rule here closes a way a support queue becomes a delivery mechanism for
an attack on the people reading it.

1. **The type is read from the bytes**, never from the filename or the
   browser's claim. A "screenshot" that is really an HTML document, served back
   as `image/png` because the client said so, is stored cross-site scripting.
2. **The list is an allow list**, and SVG is not on it — an SVG is a document
   that can carry script.
3. **The stored path is generated** and contains no part of the uploaded name.
   A filename is attacker-controlled, and `../../../../.env` is a filename.
4. **The name is flattened** to a basename with control characters removed: it
   ends up in a `Content-Disposition` header, where a newline is a header
   somebody else chose.
5. **The disk is private** and downloads go through an endpoint that checks who
   is asking, with `Content-Disposition: attachment`, `X-Content-Type-Options:
   nosniff` and a sandboxing CSP — so that even if the type were wrong, a
   browser saves the file rather than rendering it.
6. **An attachment on an internal note is not the customer's**, even on their
   own ticket.

| Setting | Default |
| --- | --- |
| `support.attachments.disk` | `local` (private) |
| `support.attachments.max_bytes` | 10 MB |
| `support.attachments.max_per_message` | 5 |
| `support.max_open_tickets_per_customer` | 20 |

The last is a bound on how much of a queue one account can occupy, not a
commercial limit. Replying on an existing ticket is never limited, so nobody is
ever stopped from asking for help.

## References

`LYN-2601-A3F91C`: the year and month, then random. Not sequential — sequential
numbering tells anybody who opened one ticket how many the platform has ever
had, and makes the neighbouring reference worth guessing at.

## Notifications and audit

The customer is told when a ticket is opened, replied to by an operator,
resolved or closed. Notifications are keyed on the *thing that happened* — the
message id, the resolution time — so a retry or a double submission produces
one. They never carry a message body: a notification is a pointer to the
ticket, not a copy of it.

Operators' state changes are audited inside the same transaction as the change:
`support.ticket_assigned`, `support.ticket_prioritised`,
`support.ticket_resolved`, `support.ticket_closed`, `support.ticket_reopened`.
Replies are not audited — the message *is* the record, it is immutable, and a
second copy of a customer's words in the audit log is a second place for them
to live.
