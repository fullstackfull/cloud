<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;

/**
 * The customer's inbox.
 *
 * Scoped to the acting customer at the query, never by a filter the client
 * sends: a notification id belonging to another account is not fetched and
 * rejected, it is simply not found. That is the same rule every other customer
 * surface here follows, and it is what makes 404 rather than 403 the honest
 * answer.
 *
 * Rendered per request in the caller's language, which is why the rows hold
 * facts rather than prose — a customer who switches to Arabic sees their whole
 * history in Arabic, including messages sent before they switched.
 */
final class NotificationController
{
    use AuthorisesWithinAccount;

    public const int MAX_PER_PAGE = 50;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly RenderNotification $renderer,
        private readonly ServiceIdentities $identities,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $perPage = min(max($request->integer('per_page', 20), 1), self::MAX_PER_PAGE);

        $notifications = $this->scoped()
            ->when(
                $request->boolean('unread'),
                static fn ($query) => $query->whereNull('read_at'),
            )
            ->orderByDesc('created_at')
            // The ULID breaks ties: two notifications raised in the same
            // millisecond must not swap places between pages.
            ->orderByDesc('id')
            ->paginate($perPage);

        $locale = $this->locale($request);
        $handles = $this->resourceHandles($notifications->items());

        return response()->json([
            'data' => array_map(
                fn (Notification $n): array => $this->present($n, $locale, $handles),
                $notifications->items(),
            ),
            'meta' => [
                'page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
                // The badge, in the same response as the list, so the portal
                // does not need a second request on every page load.
                'unread' => $this->scoped()->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        /** @var Notification $found */
        $found = $this->scoped()->whereKey($notification)->firstOrFail();

        // Idempotent, and it does not restamp: a second click must not move
        // the time somebody actually read it.
        if (! $found->isRead()) {
            $found->forceFill(['read_at' => now()])->save();
        }

        $fresh = $found->refresh();

        return response()->json([
            'data' => $this->present($fresh, $this->locale($request), $this->resourceHandles([$fresh])),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $marked = $this->scoped()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['marked_read' => $marked, 'unread' => 0]]);
    }

    /**
     * @return Builder<Notification>
     */
    private function scoped()
    {
        return Notification::query()->where('customer_id', $this->acting->get()->getKey());
    }

    private function locale(Request $request): string
    {
        $locale = $request->user()?->getAttribute('locale');

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    /**
     * The thing each notification is about, where the platform knows it.
     *
     * A notification carries its subject polymorphically — the service that
     * was built, the invoice that was issued — and until Wave 3 the inbox
     * published only a hardcoded collection path, so "your server is ready"
     * landed on the list of services and left the customer to find the row.
     *
     * Resolved rather than inferred: the subject is a stored relation, and a
     * service is turned into its concrete machine, account or site through
     * ServiceIdentities, one query per family for the whole page. Nothing
     * here guesses from a title, a timestamp or a type.
     *
     * @param  array<int, Notification>  $notifications
     * @return array<string, array{kind: string, id: string}>
     */
    private function resourceHandles(array $notifications): array
    {
        $serviceIds = [];

        foreach ($notifications as $notification) {
            if ($notification->subject_type === (new Service)->getMorphClass()
                && is_string($notification->subject_id)) {
                $serviceIds[] = $notification->subject_id;
            }
        }

        $handles = [];

        foreach ($this->identities->handlesFor(array_values(array_unique($serviceIds))) as $serviceId => $handle) {
            $handles[$serviceId] = ['kind' => $handle['kind'], 'id' => $handle['id']];
        }

        return $handles;
    }

    /**
     * @param  array<string, array{kind: string, id: string}>  $handles
     * @return array<string, mixed>
     */
    private function present(Notification $notification, string $locale, array $handles = []): array
    {
        $rendered = $this->renderer->execute($notification, $locale);

        return [
            'id' => (string) $notification->getKey(),
            'type' => $notification->type->value,
            'category' => $notification->category->value,
            'title' => $rendered->title,
            'body' => $rendered->body,
            // Whether this one reports something going wrong, so the inbox can
            // emphasise it without the portal having to know the type list.
            'is_failure' => $notification->type->isFailure(),
            'link' => $notification->link,

            /*
             * Where this notification actually points, when the platform can
             * say. `{kind, id}` rather than a path, because the routes belong
             * to the portal and an API that shipped URLs would have to be
             * redeployed the day one of them is renamed. Null where the
             * subject is not something with a page of its own — an account
             * change, a message about the account itself — and the collection
             * `link` above remains the answer.
             */
            'resource' => $this->resourceFor($notification, $handles),

            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{kind: string, id: string}>  $handles
     * @return array{kind: string, id: string}|null
     */
    private function resourceFor(Notification $notification, array $handles): ?array
    {
        $subjectId = $notification->subject_id;

        if (! is_string($subjectId) || $subjectId === '') {
            return null;
        }

        /*
         * An invoice is its own destination: Wave 2 gave it a document at
         * /invoices/{id}, and the id on the notification is that invoice.
         */
        if ($notification->subject_type === (new Invoice)->getMorphClass()) {
            return ['kind' => 'invoice', 'id' => $subjectId];
        }

        return $handles[$subjectId] ?? null;
    }
}
