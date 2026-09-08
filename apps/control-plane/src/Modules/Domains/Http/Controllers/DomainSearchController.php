<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Domains\Application\Actions\QuoteDomain;
use Lynomia\Modules\Domains\Application\Actions\SearchDomains;
use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\Services\DomainPricing;
use Lynomia\Modules\Domains\Domain\ValueObjects\RegistrableDomain;
use Lynomia\Modules\Domains\Http\Requests\QuoteDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\SearchDomainsRequest;
use Lynomia\Modules\Domains\Http\Resources\DomainQuoteResource;
use Lynomia\Modules\Domains\Http\Resources\DomainSearchResultResource;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * Looking for a name, and being told what it costs.
 *
 * **The search is not a promise.** Availability is a fact about the past by
 * the time it reaches the screen, and the price beside it is the catalogue's.
 * What the platform will actually honour is a quote row, which is why asking
 * for one is a separate, authenticated, recorded request rather than a field
 * on the search response.
 *
 * **The client never states a price.** {@see QuoteDomainRequest} has no amount
 * field. The only thing a tampered checkout can change is which quote it
 * redeems, and that is checked against the acting account.
 */
final class DomainSearchController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly SearchDomains $search,
        private readonly QuoteDomain $quote,
        private readonly DomainPricing $pricing,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function search(SearchDomainsRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        /** @var list<string> $alsoTry */
        $alsoTry = array_values($request->validated('also_try', []));

        $found = $this->search->execute((string) $request->validated('name'), $alsoTry);

        $rows = array_map(
            fn (array $row): array => $this->present($row['domain'], $row['tld'], $row['answer']),
            $found,
        );

        return response()->json([
            'data' => DomainSearchResultResource::collection($rows),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function quote(QuoteDomainRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $kind = DomainOperationKind::from((string) $request->validated('operation'));
        $termYears = (int) $request->validated('term_years', 1);

        $known = DomainTld::query()->where('enabled', true)->pluck('tld')->all();

        /** @var list<string> $known */
        $domain = RegistrableDomain::parse((string) $request->validated('name'), $known);

        /*
         * The registrar is asked again here rather than trusting whatever the
         * search put on the screen. A quote is the number the platform commits
         * to, and a premium price that arrived through the browser is a price
         * the customer chose.
         */
        $answer = $this->answerFor($domain, $kind);

        $quote = $this->quote->execute(
            $this->actingCustomer->get(),
            $domain,
            $kind,
            $termYears,
            $answer,
        );

        return (new DomainQuoteResource($quote))->response()->setStatusCode(201);
    }

    /**
     * What the registrar says now, for the operations where it matters.
     *
     * Only a registration needs an availability answer: a renewal is priced
     * from the catalogue for a name the account already holds, and asking a
     * registrar whether a name it holds is "available" answers a question
     * nobody asked.
     */
    private function answerFor(RegistrableDomain $domain, DomainOperationKind $kind): ?AvailabilityAnswer
    {
        if ($kind !== DomainOperationKind::Register) {
            return null;
        }

        $found = $this->search->execute($domain->name);

        foreach ($found as $row) {
            if ($row['domain']->name === $domain->name) {
                return $row['answer'];
            }
        }

        throw DomainRefusedException::becauseTheTldIsNotSold($domain->tld);
    }

    /**
     * One search row, priced.
     *
     * @return array{name: string, tld: string, availability: string, is_orderable: bool, premium: bool, currency: ?string, price_minor: ?int, term_years: int}
     */
    private function present(RegistrableDomain $domain, DomainTld $tld, AvailabilityAnswer $answer): array
    {
        $orderable = $answer->availability->isPurchasable();

        $priced = null;

        if ($orderable) {
            try {
                $priced = $this->pricing->forTerm($tld, DomainOperationKind::Register, 1, $answer);
            } catch (DomainRefusedException) {
                /*
                 * A name that cannot be priced cannot be ordered, and saying so
                 * is better than showing a price the checkout would then
                 * refuse. The commonest cause is a premium answer the registry
                 * gave no number for.
                 */
                $orderable = false;
            }
        }

        return [
            'name' => $domain->name,
            'tld' => $tld->tld,
            'availability' => $answer->availability->value,
            'is_orderable' => $orderable,
            'premium' => $priced !== null && $priced->premium,
            'currency' => $priced?->price->currency(),
            'price_minor' => $priced?->price->minorUnits(),
            'term_years' => 1,
        ];
    }
}
