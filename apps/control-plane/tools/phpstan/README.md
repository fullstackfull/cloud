# Static analysis toolchain

PHPStan and Larastan live in their own composer root, and the reason is
narrower than "keeping dev tools separate".

Larastan pins a range of `illuminate/*` packages. Installed alongside the
application it participates in the same dependency resolution, so a static
analysis upgrade can move a framework component the application actually runs
on — the tool that is supposed to tell you nothing changed becomes the reason
something did. Two roots make that impossible: nothing here can influence what
`apps/control-plane/composer.lock` resolves to.

It is deliberately not part of `make bootstrap`, because a new contributor does
not need it to run the application or its tests. It is installed by CI, and by
whoever is about to change a type signature:

```bash
composer install --working-dir=apps/control-plane/tools/phpstan
make lint-backend        # runs it when present, says how to install it when not
```

## Status in this environment

`composer install --working-dir=apps/control-plane/tools/phpstan` does not
complete here. Every package resolves and clones over git, except one:
`phpstan/phpstan` is distributed as an archive only — it has no git source in
the lock — and both archive endpoints (`api.github.com/.../zipball` and
`codeload.github.com`) answer 403 through this sandbox's egress proxy. Plain
`git clone` of the same repositories succeeds, which is why everything else
installs and this one package cannot.

The analysis was run anyway, and its result is real. The PHPStan distribution
repository was cloned over git at the locked tag, the toolchain was resolved
against that checkout in a scratch composer root outside this repository, and
the analysis ran from the application root against this same configuration:
level 6, the same four paths, Larastan and the deprecation rules included.

**141 errors, at level 6.** The list is genuine and is being worked through;
none of it has been silenced, and there is still no `ignoreErrors` block and no
baseline. What CI reports on its first run should match, package versions being
identical — and if it does not, CI is right and this note is wrong.
