<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * The step that turns a built hosting account into a WordPress site.
 *
 * ===========================================================================
 * WHY THIS EXISTS AT ALL
 * ===========================================================================
 *
 * Because without it the product does not work. A customer could order a
 * WordPress site, pay for it, watch the hosting account appear — and the site
 * would sit at "requested" for ever, because nothing was ever going to install
 * anything. Every piece was written: the contract, the fake, the handler, the
 * job kind, the screen. Nothing joined them.
 *
 * That is the defect this platform's gates hunt and the one they cannot see:
 * a handler registered in a service provider looks reachable to a static
 * search, and is unreachable in the product.
 *
 * ===========================================================================
 * WHY THE PASSWORD IS GENERATED HERE
 * ===========================================================================
 *
 * It has to exist somewhere, and this is the last moment before it is needed
 * and the first moment it is safe: it lives in the job payload, goes to the
 * toolkit, and reaches nothing else. Nothing writes it to the site row. The
 * customer resets it from their own dashboard, which is why keeping no copy
 * costs them nothing.
 */
final class InstallWordPressOnceTheAccountExists implements ShouldQueue
{
    public string $queue = 'provisioning';

    public int $tries = 5;

    public function __construct(
        private readonly CreateProvisioningJob $jobs,
    ) {}

    public function handle(ProvisioningJobSucceeded $event): void
    {
        if ($event->kind !== ProvisioningJobKind::CreateHostingAccount || $event->serviceId === null) {
            return;
        }

        $account = HostingAccount::query()->where('service_id', $event->serviceId)->first();

        if (! $account instanceof HostingAccount) {
            return;
        }

        /*
         * Matched through the order rather than the service, because a site
         * row is written when the customer asks and the service does not exist
         * until settlement. The order is the only identifier both halves have
         * at the moment each is created.
         */
        $orderId = Service::query()->whereKey($event->serviceId)->value('order_id');

        if (! is_string($orderId)) {
            return;
        }

        /** @var ?string $jobId */
        $jobId = DB::transaction(function () use ($account, $event, $orderId): ?string {
            $site = WordPressSite::query()
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $site instanceof WordPressSite || ! $site->state->permitsInstallation()) {
                /*
                 * Not a WordPress order, or one already being installed. The
                 * lock and this check together are what stop a redelivered
                 * event queueing a second installation over the first.
                 */
                return null;
            }

            /*
             * The account and the service are attached here; the state is
             * left alone.
             *
             * Moving it to `installing` from this listener looked tidy and
             * broke the feature: the handler refuses to install a site in a
             * state that does not permit installation, and `installing` is one
             * of those — so the job it had just queued declined to do anything.
             * The handler sets that state itself, at the moment it is true.
             */
            $site->forceFill([
                'hosting_account_id' => $account->getKey(),
                'service_id' => $event->serviceId,
            ])->save();

            /*
             * Through the platform's own job creation rather than a hand-built
             * row, so this install gets the same idempotency key handling,
             * attempt limits and timeouts as every other piece of provisioning
             * work — including the unique index that refuses a second job for
             * the same key whatever the lock above did.
             */
            $job = $this->jobs->execute(new ProvisioningJobRequest(
                kind: ProvisioningJobKind::InstallWordPress,
                idempotencyKey: 'install-wordpress:'.$site->getKey(),
                provider: $account->node?->panel->value ?? 'unknown',
                serviceId: $site->service_id,
                orderId: $site->order_id,
                customerId: (string) $site->customer_id,
                payload: [
                    'wordpress_site_id' => (string) $site->getKey(),
                    'admin_username' => $site->admin_username,

                    /*
                     * From the CSPRNG and long enough that guessing is not a
                     * strategy. Never derived from anything the customer told
                     * us, which is how a "generated" password ends up being
                     * the domain name with a number after it.
                     */
                    'admin_password' => Str::password(24),

                    'admin_email' => $site->admin_email,
                    'site_title' => $site->domain,
                    'locale' => $site->locale ?? 'en_US',
                ],
            ));

            return (string) $job->getKey();
        });

        if ($jobId === null) {
            return;
        }

        /*
         * Dispatched after the commit. A worker that picked this up first
         * would read a row that still says `requested` and do nothing — a
         * WordPress site silently never installed for a customer who paid.
         */
        RunProvisioningJob::dispatch($jobId);
    }
}
