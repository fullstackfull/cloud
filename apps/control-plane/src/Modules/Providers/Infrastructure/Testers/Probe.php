<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;

/**
 * The only way a tester makes a request, and the running record of what it
 * tried.
 *
 * Two jobs, and the second is why it is a class rather than a closure.
 *
 * It holds the one already-credentialled request that
 * {@see HttpIdentityTester} built — with verification on, redirects refused
 * and a deadline set — so a subclass cannot assemble a second one on softer
 * terms. A tester that wanted to skip certificate verification for one call
 * would have to stop using this object, which is a visible thing to do rather
 * than a parameter to flip.
 *
 * And it collects the steps, so the account of what happened is written as it
 * happens. A test that threw halfway through still has its earlier steps, and
 * an operator reading a failure can see how far it got.
 */
final class Probe
{
    /** @var list<ConnectionStep> */
    public array $steps = [];

    /** How many requests have actually gone out. */
    public int $requests = 0;

    public function __construct(private readonly PendingRequest $request) {}

    /**
     * A read.
     *
     * GET only. There is no post() and there will not be one: a connection
     * test never changes anything, which is what makes it safe to offer as a
     * button and safe to run on a schedule against a machine classified
     * do-not-touch.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws RedirectRefused
     * @throws ConnectionException
     */
    public function get(string $path, array $query = []): Response
    {
        $this->requests++;

        $response = $this->request->get($path, $query);

        if ($response->redirect()) {
            throw RedirectRefused::at($path, $response->status());
        }

        return $response;
    }

    public function passed(string $name, ?string $detail = null): void
    {
        $this->steps[] = ConnectionStep::passed($name, $detail);
    }

    public function failed(string $name, ?string $detail = null): void
    {
        $this->steps[] = ConnectionStep::failed($name, $detail);
    }
}
