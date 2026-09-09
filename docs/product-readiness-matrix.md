# Product readiness matrix

Where each product the platform sells stands on the readiness ladder, what it
needs from which provider, and the one thing in the way of the next rung.
Produced at the closure of Phase 30B-P from the requirement matrix in source
(`ProductRequirements`) and the ladder the engine climbs
(`ProductReadinessEvaluator`), not from a plan.

## The ladder

| Rung | Meaning | Who can put a product here |
| --- | --- | --- |
| `not_ready` | A requirement is unmet: no provider in the category, or none that has answered a connection test and had its capabilities discovered, or one that lacks a capability the product calls. | The engine |
| `ready_for_test` | Every requirement is met by a **proven** provider — answered, discovered, Ready or Enabled — and a controlled (fake) one counts. The whole lifecycle can be rehearsed. | The engine |
| `ready_for_real_validation` | Every requirement is met by a proven provider that is **not controlled**. Somebody real has answered; nothing real has been sold through it. | The engine |
| `ready_for_production` | Every requirement is met by a real provider **enabled in the production environment**. Nothing in the control plane is stopping live use. This is not proof anything works. | The engine |
| `ready_to_sell` | `ready_for_production`, **and** a person has declared that the product was validated against those providers, with a reason and a reference to the evidence. Withdrawn automatically, audited, the moment any requirement falls below production. | A person, under `readiness.declare`, which no operator role holds by default |

A controlled driver (`fake`, `fake_bmc`) carries a requirement to
`ready_for_test` and never higher, whatever environment its row says and
whatever state an operator put it in. This is tested at the evaluator and over
HTTP with a fake enabled in production.

## Requirements

Own requirements name the capabilities the product's code calls — narrower than
the category's whole question set. Every product also carries the two shared
requirements that selling anything needs.

| Product | Depends on | Own requirements (category: capabilities) | Shared requirements |
| --- | --- | --- | --- |
| Cloud VPS (`vps`) | — | compute: create, start, stop, reboot, reinstall, suspend, unsuspend, destroy, task_polling · reverse_dns: set_ptr, clear_ptr | payment: charge, webhook · email: send |
| Dedicated servers (`dedicated`) | — | bmc: power_state, power_control, boot_override · reverse_dns: set_ptr, clear_ptr | same |
| Shared hosting (`shared_hosting`) | — | hosting: create_account, suspend, unsuspend, terminate, change_package | same |
| WordPress (`wordpress`) | `shared_hosting` | wordpress_installer: install, uninstall, ssl | same |
| Domains (`domains`) | — | registrar: search, availability, register, renew, transfer, nameservers, contacts, lock, auth_code | same |
| DNS (`dns`) | — | dns: create_zone, delete_zone, records, reconcile | same |
| Backups (`backups`) | `vps` | backup: create, restore, delete, retention | same |

A dependency caps its dependent: WordPress can never be readier than shared
hosting, backups never readier than VPS. A declaration on the dependency does
not carry over — the dependent inherits how far the dependency has been
*proven*, at most `ready_for_production`.

## Where each product stands on this build

On the closing commit of Phase 30B-P, with the providers a fresh install can
have. The engine's verdict is the same on the development seed and on the
browser-suite seed: **every product is `not_ready`**, for the reasons below.
No provider on this build is real *and* proven, because no real driver has a
connection tester (Phase 30B closed NO GO with no real endpoint), so no product
can climb past `ready_for_test` even in principle, and none reaches that.

| Product | Rung | First blocker (in dependency order) | What would move it |
| --- | --- | --- | --- |
| Cloud VPS | `not_ready` | `blocked_dependency` — no compute provider registered | Register a `proxmox` provider on a classified machine; it then stops at `blocked_configuration` — "no connection tester exists for the proxmox driver yet" — until one is written against a real node |
| Dedicated servers | `not_ready` | `blocked_dependency` — no BMC provider registered (the browser seed has a `fake_bmc`, which stops the product at `ready_for_test` once tested and enabled, and no higher) | A real `ipmi` / `redfish` / `ilo` provider with a tester |
| Shared hosting | `not_ready` | `blocked_dependency` — no hosting provider registered | A `cpanel` or `directadmin` provider with a licence that permits and a tester written against a real panel |
| WordPress | `not_ready` | `blocked_dependency` — depends on shared hosting, which is `not_ready` | Shared hosting first; then a `wordpress_installer` provider |
| Domains | `not_ready` | `blocked_dependency` — no registrar provider registered | A registrar selected (Phase 30B forbade selecting one from memory) and a `sy_registry` or other tester against a sandbox |
| DNS | `not_ready` | `blocked_dependency` — no DNS provider registered (the browser seed has a `fake` DNS provider blocked on credentials) | A `cloudflare` provider with a credential the controller holds and a tester against a real account |
| Backups | `not_ready` | `blocked_dependency` — depends on VPS, which is `not_ready` | VPS first; then a `proxmox_backup` provider |

And the shared requirements are unmet for every product: no payment provider
(`stripe` is catalogued, untestable) and no email provider (SMTP has neither a
catalogue entry nor an adapter) is registered.

## What each rung costs, for a real provider

For any real driver, the path from here to `ready_to_sell` is the same and
every step of it is a thing this phase cannot do:

1. A connection tester written against the real endpoint (`ConnectionTester`
   for the driver; the platform then reports `testable=true`).
2. A credential the deployment controller holds, attached; a licence that
   permits, where the catalogue says one is needed.
3. A connection test that reaches `connected` and discovers the capabilities
   the product calls, all `supported`.
4. The provider enabled, in the production environment.
5. Every other requirement of the product, and of its dependencies, at the
   same rung.
6. A person's declaration, with a reference to the validation evidence.

Steps 1–5 are what the engine checks. Step 6 is the one it never performs.

## The statement this matrix exists to make

**No product on this build is `READY_TO_SELL`, none is `READY_FOR_PRODUCTION`,
none is `READY_FOR_REAL_VALIDATION`, and none is `READY_FOR_TEST`.** The engine
reaches those verdicts from the providers as they are, and it would report the
first fake that answered as `ready_for_test` — and refuse, in the same breath,
to let it count for anything above that.
