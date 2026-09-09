# A credential reference has no value behind it

## What you are seeing

`CredentialMissing`, or a credential on the Credentials screen marked
"not on controller".

## What it means

The platform stores where a secret lives — a variable name on the deployment
controller — and never the secret. A reference is *missing* when the
controller's environment has no value under that name. Every provider and
machine pointing at it reports a credentials blocker and cannot be tested or
enabled.

## Fix

On the deployment controller, put the value in the environment the control
plane's processes read, under exactly the variable name the Credentials screen
shows, then restart the workers so they read it:

```bash
php artisan queue:restart
```

Then, on the Credentials screen, mark the credential **rotated**. That re-reads
presence, drops the state to *configured*, and reassesses every provider that
uses it. A connection test on one of those providers is what makes it *valid*.

## Do not

- Paste the value anywhere in the control plane. The record endpoint refuses a
  value-shaped reference and any field it does not know, and tells you to
  rotate what you pasted.
