# Static analysis toolchain

PHPStan and Larastan live in their own Composer manifest rather than in
`apps/control-plane/composer.json`. This is deliberate:

- Analyser dependencies can never force a downgrade of an application runtime
  dependency, and vice versa.
- The application's production dependency graph stays free of analysis tooling.

## Usage

```bash
composer install --working-dir=tools/phpstan
tools/phpstan/vendor/bin/phpstan analyse -c phpstan.neon
```

`make lint-backend` does both steps.

## Note for restricted networks

`phpstan/phpstan` is distributed as a dist-only package hosted on
`api.github.com`. In environments where egress policy blocks that host the
install will fail; CI (GitHub Actions) has unrestricted access and runs the
analysis on every pull request.
