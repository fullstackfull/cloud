<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * Looking at the sites, so that "ready" means somebody looked.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * Because every other signal in this flow is a claim by somebody else. The
 * panel says the account exists. The installer says WordPress is installed.
 * The certificate authority says a certificate was issued. All three can be
 * true while the customer's site serves a database error, a holding page, or
 * somebody else's website because the name still points at their old host.
 *
 * A platform that marked the site ready on the strength of those three claims
 * would be telling the customer their site works and finding out otherwise
 * from a support ticket. So the last step is to fetch it.
 *
 * ===========================================================================
 * WHAT IT WILL NOT DO
 * ===========================================================================
 *
 * It never installs, never re-points DNS, never re-orders a certificate. It
 * reads and it records — which is what makes it safe to run on a clock across
 * every site on the platform, including the ones the Timeout Rule has put out
 * of bounds for anything that writes.
 *
 * A site that answers and is not WordPress is recorded as still waiting on
 * DNS rather than failed, because that is almost always what it is: a name
 * still pointed at the customer's old host. Calling it a failure sends them to
 * support; calling it a delegation sends them to their registrar, which is
 * where the fix is.
 */
final readonly class VerifyWordPressSites
{
    public function __construct(
        private SiteProbe $probe,
    ) {}

    /**
     * @return array{checked: int, verified: int, waiting: int, insecure: int}
     */
    public function execute(int $limit = 200): array
    {
        $sites = WordPressSite::query()
            ->where('installed', true)
            ->whereIn('state', [
                WordPressSiteState::AwaitingCertificate->value,
                WordPressSiteState::AwaitingDns->value,
                WordPressSiteState::Ready->value,
            ])
            ->orderByRaw('verified_at is null desc, reconciled_at asc nulls first')
            ->limit($limit)
            ->get();

        $verified = 0;
        $waiting = 0;
        $insecure = 0;

        foreach ($sites as $site) {
            $url = $site->site_url ?? 'https://'.$site->domain;

            $result = $this->probe->probe($url);

            $site->reconciled_at = CarbonImmutable::now();

            if (! $result->reachable || ! $result->isWordPress) {
                /*
                 * Not a failure. The overwhelmingly common cause is a name
                 * that has not finished pointing here, and a site that has
                 * been marked ready before now goes back to waiting rather
                 * than staying green over a site nobody can reach.
                 */
                $site->forceFill([
                    'state' => WordPressSiteState::AwaitingDns,
                    'dns_ready' => false,
                    'verified_at' => null,
                    'reconciled_at' => $site->reconciled_at,
                ])->save();

                $waiting++;

                continue;
            }

            if (! $result->secure) {
                /*
                 * The site works and the padlock does not. Its own state,
                 * because the advice differs: this customer should be told
                 * their site is up and the certificate is coming, not that
                 * something is wrong.
                 */
                $site->forceFill([
                    'state' => WordPressSiteState::AwaitingCertificate,
                    'dns_ready' => true,
                    'ssl_status' => SslStatus::Pending,
                    'verified_at' => null,
                    'reconciled_at' => $site->reconciled_at,
                ])->save();

                $insecure++;

                continue;
            }

            $site->forceFill([
                'state' => WordPressSiteState::Ready,
                'dns_ready' => true,
                'ssl_status' => SslStatus::Active,
                'verified_at' => CarbonImmutable::now(),
                'failure_reason' => null,
                'reconciled_at' => $site->reconciled_at,
            ])->save();

            $verified++;
        }

        return [
            'checked' => $sites->count(),
            'verified' => $verified,
            'waiting' => $waiting,
            'insecure' => $insecure,
        ];
    }
}
