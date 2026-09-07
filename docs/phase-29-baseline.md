# Phase 29 — Baseline, re-verified

Run before touching anything, on `claude/hv-t6hq1p` at `570a1e0`, in this
environment. Every line is a command's own output.

## The gates

```text
php artisan test                    1797 tests, 41371 assertions, 0 failures
./vendor/bin/pint --test            PASS
composer validate --strict          ./composer.json is valid
phpstan (level 6, larastan)         0 errors
npm run test --workspace=apps/web   42 tests in 6 files
npm run typecheck                   PASS
npm run lint                        PASS
npm run build                       428.45 kB JS / 126.58 kB gzipped
                                    23.61 kB CSS / 5.71 kB gzipped
npm run openapi:lint                valid, 1 warning (the 302 email-verification
                                    endpoint has no 2xx, by design)
playwright test                     35 specs passed
migrate:fresh --seed                23 migrations, 69 tables
migrate:rollback --step=100         all rolled back
migrate                             23 re-applied, 69 tables
```

Every figure the previous report claimed is reproduced exactly. The baseline is
what it says it is.

## What the inspection of configuration found

Three things worth recording before the phase starts, because two of them are
gaps that the reported baseline does not mention.

**1. Horizon is a dependency and has no configuration.** `laravel/horizon`
^5.48 is required, the Ansible role installs a `lynomia-horizon` systemd unit,
and there is no `config/horizon.php` in the repository. Horizon would therefore
run with the package defaults, whose single supervisor watches the `default`
queue — while this platform's work is on `provisioning` and `payments`. Nothing
would consume them.

**2. The queue's configured default is `database`, and the shipped environment
says `redis`.** `config/queue.php` falls back to `database`; `.env.example` sets
`QUEUE_CONNECTION=redis`. Both work, but they disagree, and the fallback is the
one that applies when an operator forgets the variable.

**3. The queues actually in use are three**, and one of them is implicit:

```text
provisioning   RunProvisioningJob            explicit, const QUEUE
payments       FulfilOrderOnInvoicePaid      explicit, $queue property
               SettleInvoiceOnPaymentCaptured
               RecordRefundAgainstTheInvoice
default        PublishReverseDnsRecord       no queue declared
```

## Documents inspected

`docs/build-status.md`, `docs/system-completion-report.md`,
`docs/performance-report.md`, `docs/architecture.md`, `docs/openapi.yaml`, the
CI workflow, `routes/console.php`, `config/queue.php` and the provider registry.
The five gaps the completion report lists are still exactly as described there;
this phase closes them.
