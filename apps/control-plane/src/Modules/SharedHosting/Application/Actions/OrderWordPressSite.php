<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\WordPressRefusedException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * Asking for a WordPress site, and the four answers to "which name".
 *
 * ===========================================================================
 * WHY THE NAME IS THE HARD PART
 * ===========================================================================
 *
 * Everything else about a WordPress order is the hosting product this platform
 * already sells. The name is what makes it a different order, because the four
 * ways a customer can supply one have wildly different timelines:
 *
 *  - **Register it here** — delegated in seconds, once the registration lands.
 *  - **Use one already held here** — delegated immediately.
 *  - **Transfer it in** — up to five days, and none of that is this platform's
 *    to shorten.
 *  - **Point one held elsewhere** — indefinite, because it waits on the
 *    customer making a change at another company.
 *
 * The row records which of the four it is, and it is recorded rather than
 * inferred, because the screen has to say different things while waiting.
 * A spinner is the wrong answer to three of these four.
 *
 * ===========================================================================
 * WHAT THIS DOES NOT DO
 * ===========================================================================
 *
 * It does not register the domain. A customer choosing "register it here"
 * places a domain order too, through the domain paths, with the domain rules
 * about money and the Timeout Rule — and this row is linked to it. Two
 * products bought together are still two products, and a WordPress order that
 * registered a domain by a side path would be a second registration engine.
 */
final readonly class OrderWordPressSite
{
    /**
     * @throws WordPressRefusedException
     */
    public function execute(
        Customer $customer,
        string $domain,
        WordPressDomainSource $source,
        string $adminUsername,
        string $adminEmail,
        string $locale = 'en_US',
    ): WordPressSite {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        if ($domain === '' || ! str_contains($domain, '.')) {
            throw WordPressRefusedException::becauseTheDomainIsUnusable($domain);
        }

        return DB::transaction(function () use ($customer, $domain, $source, $adminUsername, $adminEmail, $locale): WordPressSite {
            $taken = WordPressSite::query()
                ->where('domain', $domain)
                ->whereNotIn('state', [WordPressSiteState::Removed->value, WordPressSiteState::Failed->value])
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                /*
                 * Two sites answering for one name is a race between two
                 * installations, and whichever loses leaves a certificate
                 * order and a document root nobody owns.
                 */
                throw WordPressRefusedException::becauseTheDomainIsAlreadyUsed($domain);
            }

            $held = $this->heldHere($customer, $domain);

            if ($source->isDelegatedByThisPlatform() && $held === null) {
                /*
                 * The customer said the name is theirs here and it is not —
                 * usually because a registration is still in flight. Refused
                 * rather than silently downgraded to "external", which would
                 * leave them waiting for a delegation nobody is going to make.
                 */
                throw WordPressRefusedException::becauseTheDomainIsNotHeldHere($domain);
            }

            return WordPressSite::query()->create([
                'customer_id' => $customer->getKey(),
                'domain' => $domain,
                'domain_id' => $held?->getKey(),
                'domain_source' => $source,

                /*
                 * A name this platform delegates starts at `requested` — the
                 * next step is building the account. One that waits on
                 * somebody else starts at `awaiting_dns`, so the very first
                 * screen the customer sees says what they have to go and do.
                 */
                'state' => $source->isDelegatedByThisPlatform()
                    ? WordPressSiteState::Requested
                    : WordPressSiteState::AwaitingDns,

                /*
                 * Stated rather than left to the column defaults. A row
                 * created and immediately serialised would otherwise carry
                 * nulls where the schema has values, and the screen would
                 * render its first view of a new site from data the database
                 * has and the object does not.
                 */
                'dns_ready' => false,
                'installed' => false,
                'ssl_status' => SslStatus::Unknown,

                'admin_username' => $adminUsername,
                'admin_email' => $adminEmail,
                'locale' => $locale,
            ]);
        });
    }

    /**
     * The domain row behind the name, when this account holds it here.
     *
     * Scoped to the customer: a name held by somebody else is not a name this
     * customer may point at their own hosting, and answering "not held here"
     * is also the answer that leaks nothing about who does hold it.
     */
    private function heldHere(Customer $customer, string $domain): ?Domain
    {
        return Domain::query()
            ->where('customer_id', $customer->getKey())
            ->where('name', $domain)
            ->whereIn('state', DomainState::thatHoldTheName())
            ->first();
    }
}
