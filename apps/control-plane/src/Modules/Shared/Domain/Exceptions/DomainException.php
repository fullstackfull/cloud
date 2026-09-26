<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/**
 * Base class for every exception raised by domain logic.
 *
 * Domain exceptions carry a stable machine-readable code so the HTTP layer can
 * translate them into a consistent JSON error body without string matching on
 * messages.
 *
 * ---------------------------------------------------------------------------
 * One context, two readers
 * ---------------------------------------------------------------------------
 *
 * `context()` is everything the raising code knew. It is what the log reads,
 * what a failure report records, and what the customer catalogue fills its
 * `:placeholders` from. It is allowed to name a provider, a driver, a node, a
 * cluster or the configuration key a credential is read from, because the
 * person reading a log needs exactly that.
 *
 * `publishedContext()` is the part of it a caller is shown, as
 * `error.details`. It is empty unless the class says otherwise, and a class
 * says otherwise by naming each key with `publishing(...)`, beside the
 * `withContext()` that sets it. The renderer in `bootstrap/app.php` reads this
 * and nothing else, so a new exception cannot widen a response without
 * somebody writing the name of what it widens.
 *
 * This used to be one reader. The whole context was published, and the
 * exceptions that must not reach a customer were held off the wire by hand —
 * rewrapped at one module's edge, converted in one controller, guarded by
 * assertions listing the keys they hoped were absent — while two customer
 * routes that nobody had wrapped answered with the DNS provider's name and
 * the configuration key its token is read from.
 *
 * ---------------------------------------------------------------------------
 * What may be declared
 * ---------------------------------------------------------------------------
 *
 * The rule is not "is it sensitive" but **whether the caller already knows
 * it**: a value they sent, a field on their own form, the state of their own
 * resource. Telling them that back costs nothing and saves them a request.
 * Everything they would *learn* from us — which provider, which node, which
 * configuration key, why the estate cannot place something — stays in the log.
 *
 * ---------------------------------------------------------------------------
 * What the engine refuses, whoever asks
 * ---------------------------------------------------------------------------
 *
 *  - The declared list is `private`. A subclass that declares a property of
 *    the same name gets a second property this class never reads.
 *  - `publishing()` and `publishedContext()` are `final`, and `publishing()`
 *    is `protected`: nothing outside the exception can widen it.
 *  - A declared key whose name contains a word from `NEVER_PUBLISHED`, in any
 *    case and with any digits around it, is dropped when the context is read.
 *    That runs at read time rather than at the call site so it holds for
 *    every way PHP can make the call, including the ones no source scanner
 *    reads — a key built at runtime or held in a constant, a spread, a call
 *    made through a closure or `array_map`. The forms a scanner does read
 *    (`->`, `?->` and `::` calls with literal arguments) are refused a second
 *    time, on the day they are written, by
 *    `tests/Feature/Api/ErrorDetailsAreOnlyWhatTheCallerAlreadyKnowsTest`,
 *    which also fails on any declaration whose arguments are not literals.
 *  - A declared key the context does not carry is not published at all,
 *    rather than published as null.
 *
 * What this cannot stop is a caller-owned name holding a value that is not
 * the caller's — `publishing('status')` beside a status set to a hostname.
 * That is a code review question, and the inventory in the test above is
 * where the review happens.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Words a published key may not contain.
     *
     * Each names something about the platform rather than about the caller's
     * request. Final, so a subclass cannot redeclare it empty.
     *
     * @var list<string>
     */
    final public const array NEVER_PUBLISHED = [
        'provider',
        'driver',
        'node',
        'cluster',
        'credential',
        'configuration',
        'panel',
        'datastore',
        'hypervisor',
        'bmc',
        'secret',
        'password',
    ];

    /** @var array<string, scalar|null> */
    protected array $context = [];

    /**
     * The context keys a caller may be shown. Private: see the class docblock.
     *
     * @var list<string>
     */
    private array $published = [];

    /**
     * Stable, machine-readable identifier, e.g. "money.currency_mismatch".
     */
    abstract public function errorCode(): string;

    /**
     * HTTP status this exception should map to when it escapes to the API.
     */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * Everything the raising code knew, for the log and the catalogue.
     *
     * Never a response body on its own; {@see publishedContext()} is.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * The part of the context a caller is shown as `error.details`.
     *
     * Only declared keys, only those the context carries, and never one that
     * names the platform.
     *
     * @return array<string, scalar|null>
     */
    final public function publishedContext(): array
    {
        $context = $this->context();
        $published = [];

        foreach ($this->published as $key) {
            if (! array_key_exists($key, $context) || self::namesThePlatform($key)) {
                continue;
            }

            $published[$key] = $context[$key];
        }

        return $published;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    protected function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Declare context keys the caller already knows, so they may be shown.
     *
     * Adds to what was declared before rather than replacing it, so a named
     * constructor and a shared helper can each declare their own keys.
     */
    final protected function publishing(string ...$keys): static
    {
        $this->published = array_values(array_unique([...$this->published, ...$keys]));

        return $this;
    }

    private static function namesThePlatform(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::NEVER_PUBLISHED as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }
}
