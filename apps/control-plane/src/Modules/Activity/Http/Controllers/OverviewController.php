<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Activity\Application\DTOs\AttentionItem;
use Lynomia\Modules\Activity\Application\Queries\AccountOverview;
use Lynomia\Modules\Activity\Http\Resources\ActivityItemResource;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * The dashboard, in one request.
 *
 * `GET /api/v1/me/overview`. One read answers what needs attention, what the
 * account holds, what it owes, what renews next, how many notifications are
 * unread and what happened recently — because the alternative is a first page
 * that fans out across four product endpoints and decides in the browser which
 * of the results matters.
 *
 * Nothing here has an identifier in it. The account is the token's, so there is
 * no parameter that could name another one.
 */
final class OverviewController
{
    use AuthorisesWithinAccount;
    use SerialisesMoney;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly AccountOverview $overview,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function show(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $customer = $this->actingCustomer->get();
        $overview = $this->overview->for($customer);

        return response()->json([
            'data' => [
                'attention' => array_map(
                    fn (AttentionItem $item): array => $this->attention($item),
                    $overview['attention'],
                ),

                'services' => $overview['services'],

                'billing' => [
                    /*
                     * One entry per currency, each already money-shaped. There
                     * is deliberately no `total`: an account billed in two
                     * currencies has two amounts owing, and a single number
                     * would be arithmetic nobody can perform.
                     */
                    'due' => array_map(
                        fn (array $due): array => [
                            'invoices' => $due['invoices'],
                            'amount' => $this->moneyOfMinor($due['minor_units'], $due['currency']),
                        ],
                        $overview['billing']['due'],
                    ),
                ],

                'renewals' => array_map(
                    fn (array $renewal): array => [
                        'kind' => $renewal['kind'],
                        'resource' => [
                            'kind' => $renewal['resource_kind'],
                            'id' => $renewal['resource_id'],
                            'identity' => $renewal['identity'],
                        ],
                        'at' => $renewal['at'],
                        // Null where the platform has no authoritative amount,
                        // rather than a plausible one.
                        'amount' => $renewal['currency'] === null || $renewal['minor_units'] === null
                            ? null
                            : $this->moneyOfMinor($renewal['minor_units'], $renewal['currency']),
                    ],
                    $overview['renewals'],
                ),

                'unread_notifications' => $overview['unread_notifications'],

                'recent' => [
                    'services' => $overview['recent']['services'],

                    // The account feed's own rows, not a second history.
                    'activity' => ActivityItemResource::collection(
                        $this->overview->recentActivity($customer),
                    ),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attention(AttentionItem $item): array
    {
        return [
            'id' => $item->id,
            // A code the portal renders, so the sentence is in the reader's
            // language and the ordering is not.
            'kind' => $item->kind,
            'severity' => $item->severity->value,
            'occurred_at' => $item->occurredAt->toIso8601String(),
            'resource' => $item->resourceKind === null || $item->resourceId === null ? null : [
                'kind' => $item->resourceKind,
                'id' => $item->resourceId,
                'identity' => $item->resourceIdentity,
            ],
            'reference' => $item->reference,
        ];
    }
}
