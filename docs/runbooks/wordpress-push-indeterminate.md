# WordPress push indeterminate

**Alert:** `WordPressPushIndeterminate` — a push of a staging copy over a
live WordPress site has no confirmed outcome from the panel's toolkit.

## What happened

A customer pushed their staging copy over production (files, database or
both). The platform asked the panel's toolkit and it did not answer before
the deadline. The operation is `indeterminate`, the production site row is
`needs_review` with the reason, the customer has been told the live site
may be partly updated and asked not to push again, and **the platform will
not push again**: a second push over a first that is still running cannot
be reasoned about afterwards.

## Why it matters

The live site is in one of three states — the copy, the old site, or a mix
of both — and only the toolkit's own log says which. Until a person settles
it, the customer cannot push or copy anything involving either site.

## What to do

1. Find the operation and the site:

   ```sh
   php artisan tinker --execute="dump(\Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation::query()->where('kind','push_to_production')->where('state','indeterminate')->with('site:id,domain','target:id,domain')->get()->toArray())"
   ```

2. Look at the toolkit on the node the account is on. (There is no cPanel
   or DirectAdmin staging adapter in this build; an indeterminate push with
   the fake panel is a test or a development environment.) Load the live
   site.

3. Settle the operation with what you found, from a tinker session, and
   tell the customer through their ticket:

   - The push finished → set the operation `succeeded`, `finished_at` now;
     set the production site's `state` back to `ready` and clear
     `review_reason`. The next verification pass re-checks the site.
   - The push did not happen → set the operation `failed` with the reason;
     set the production site back to `ready`. The customer may push again.
   - The site is a mix → this is the case the runbook exists for. Restore
     it in the panel (from the panel's own backup, if one was taken; the
     platform holds none), then settle as above.

## What not to do

Do not push again to "finish the job". Do not delete the operation row: it
carries the impact plan the customer confirmed, and the audit row
`wordpress.push.requested` points at it.
