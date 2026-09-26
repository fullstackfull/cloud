<?php

declare(strict_types=1);

namespace Tests\Support;

use Lynomia\Modules\Shared\Domain\Contracts\HostResolver;

/**
 * A resolver that answers from a table and remembers what it was asked.
 *
 * Every name it has not been told about resolves to one public address, which
 * the endpoint policy accepts for every caller and in every environment: not
 * private, not reserved, not a documentation address. Fixtures name hosts that
 * do not exist — `bmc-01.mgmt.lynomia-fleet.net`, `panel.example.test` — and
 * the policy refuses a name it can see no address for, so a resolver that
 * answered nothing by default would turn every fixture name into a refusal.
 * Nothing dials the default answer; it only has to be an address the policy
 * judges as ordinary.
 *
 * A test that is about what a name resolves to says so with `answer()`, and
 * one that is about whether a name was resolved at all reads `asked()`.
 */
final class StaticHostResolver implements HostResolver
{
    public const string ORDINARY_ANSWER = '8.8.8.8';

    /** @var array<string, list<string>> */
    private array $answers = [];

    /** @var list<string> */
    private array $asked = [];

    /**
     * @param  list<string>  $addresses
     */
    public function answer(string $host, array $addresses): self
    {
        $this->answers[strtolower($host)] = $addresses;

        return $this;
    }

    public function addressesFor(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[strtolower($host)] ?? [self::ORDINARY_ANSWER];
    }

    /**
     * @return list<string> every name asked about, in order, as it was asked
     */
    public function asked(): array
    {
        return $this->asked;
    }
}
