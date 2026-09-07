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

The first run reported **141 errors at level 6**. Seventy-one of them were the
analyser rather than the code: this project declares casts in the `casts()`
method, and Larastan reads the `$casts` property unless `parseModelCastsMethod`
is set, so it saw no casts at all and reported every cast attribute as its raw
column type. That is now set, in `phpstan.neon`, with the reasoning next to it.

The remaining seventy were worked through one at a time, and the run is now at
**zero**. Three were defects — a missing Eloquent relation that made an operator
endpoint 500 as soon as it had a row to return, a call to a brick/money method
that does not exist, and a catch block that could never run and so misreported a
configuration mistake as a panel outage. The rest were annotations that claimed
more than the code guaranteed, defensive branches an exhaustive match had
already made unreachable, and a factory trait on four models that had no
factory. Every one of them was fixed rather than silenced: there is still no
`ignoreErrors` block and no baseline, and if CI's first run disagrees with this
note, CI is right and this note is wrong.
