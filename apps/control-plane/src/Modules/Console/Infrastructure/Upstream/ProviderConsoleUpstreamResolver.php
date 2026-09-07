<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure\Upstream;

use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Console\Domain\Contracts\ConsoleUpstreamResolver;
use Lynomia\Modules\Console\Domain\Exceptions\ConsoleUpstreamUnavailableException;
use Lynomia\Modules\Console\Domain\ValueObjects\ConsoleUpstream;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * Asks the machine's own hypervisor where its console is.
 *
 * Resolved through the cluster the machine actually sits on rather than a
 * fleet default, for the same reason every other provider call in this
 * platform is: a platform mid-migration runs two clusters on two hypervisors,
 * and a console dialled at the wrong one is either a failure or — worse — a
 * connection to a machine with the same id belonging to somebody else.
 */
final readonly class ProviderConsoleUpstreamResolver implements ConsoleUpstreamResolver
{
    public function __construct(
        private ComputeProviderFactory $providers,
    ) {}

    public function resolve(ConsoleSession $session): ConsoleUpstream
    {
        $machine = VirtualMachine::query()->find($session->virtualMachineId);

        if ($machine === null) {
            throw ConsoleUpstreamUnavailableException::because('the machine no longer exists');
        }

        $node = $machine->node()->first();
        $cluster = $machine->cluster()->first();

        if ($node === null || $cluster === null || ! $machine->existsRemotely()) {
            throw ConsoleUpstreamUnavailableException::because('the machine has no confirmed node or cluster');
        }

        try {
            $endpoint = $this->providers->for($cluster)
                ->consoleEndpoint($node->provider_name, (string) $machine->provider_id);
        } catch (ComputeProviderException $e) {
            /*
             * The provider's message is deliberately not carried forward into
             * anything the customer sees; it is kept here so an operator
             * reading the gateway's log knows whether the cluster refused, was
             * unreachable, or has no such machine.
             */
            throw ConsoleUpstreamUnavailableException::because($e->errorCode());
        }

        return new ConsoleUpstream(
            host: $endpoint->host,
            port: $endpoint->port,
            path: $endpoint->path,
            tls: $endpoint->tls,
            headers: $endpoint->headers,
            verifyTls: $endpoint->verifyTls,
        );
    }
}
