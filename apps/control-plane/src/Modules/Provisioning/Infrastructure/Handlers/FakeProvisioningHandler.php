<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Handlers;

use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ProvisioningFailedException;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use RuntimeException;

/**
 * A handler that builds nothing and reaches no network.
 *
 * Its behaviour is a pure function of the job's payload: `fake.outcome`
 * selects the ending, so a test asks for a capacity failure by queueing a job
 * rather than by wiring a mock. That keeps the engine's own tests written in
 * terms of jobs, which is what production will hand it.
 *
 * Two details are deliberate and look like over-engineering until the
 * alternative bites:
 *
 *  - the remote job id is published through recordRemoteJobId() before the
 *    outcome is decided, exactly as a real handler must. A fake that returned
 *    the id instead would let the engine's most important invariant — the id
 *    is durable before the call can time out — pass a suite that never
 *    exercised it;
 *  - the response metadata always carries credential-shaped values, because a
 *    fake whose responses were clean would mean the redaction that stands
 *    between a support console and a customer's root password was never
 *    executed by the tests that are supposed to cover it.
 */
final class FakeProvisioningHandler implements ProvisioningHandler
{
    /** Payload key under which a test states what should happen. */
    public const string CONFIG_KEY = 'fake';

    public function __construct(
        private readonly ProvisioningJobKind $handles = ProvisioningJobKind::CreateVps,
    ) {
        if (app()->isProduction()) {
            throw new RuntimeException('The fake provisioning handler must never be registered in production.');
        }
    }

    public function kind(): ProvisioningJobKind
    {
        return $this->handles;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        $config = $this->configFor($job);

        $remoteJobId = is_string($config['remote_job_id'] ?? null)
            ? (string) $config['remote_job_id']
            : 'fake-job-'.substr(hash('sha256', (string) $job->getKey()), 0, 12);

        /*
         * Published first, before anything that can fail. This is the ordering
         * a real handler must follow: the provider has the work, and from this
         * moment on a crash must not be able to erase the fact that it does.
         */
        if (($config['announce_remote_job_id'] ?? true) !== false) {
            $job->recordRemoteJobId($remoteJobId);
        }

        $outcome = is_string($config['outcome'] ?? null) ? (string) $config['outcome'] : 'succeed';
        $metadata = $this->response($remoteJobId);

        return match ($outcome) {
            'succeed' => ProvisioningResult::succeeded(
                remoteJobId: $remoteJobId,
                providerReference: is_string($config['provider_reference'] ?? null)
                    ? (string) $config['provider_reference']
                    : 'fake-vm-'.substr(hash('sha256', (string) $job->getKey()), 12, 8),
                metadata: $metadata,
            ),

            // Returned rather than thrown: a provider that answers "no room"
            // has not malfunctioned, and the engine must be able to tell the
            // difference.
            'capacity' => ProvisioningResult::failed(
                failureClass: FailureClass::Capacity,
                errorCode: 'fake.capacity',
                errorMessage: 'The fake provider has no room for this shape.',
                remoteJobId: $remoteJobId,
                metadata: $metadata,
            ),

            'transient' => throw ProvisioningFailedException::transient(
                'The fake provider was briefly unavailable.',
                'fake.transient',
            ),

            'permanent' => throw ProvisioningFailedException::permanent(
                'The fake provider rejected this request and will reject it again.',
                'fake.permanent',
            ),

            'timeout' => throw ProvisioningFailedException::timedOut(
                'The fake provider did not answer within the deadline.',
                'fake.timeout',
            ),

            // An adapter bug, an SDK exception, a type error: whatever it is,
            // it arrives unclassified and the engine has to decide for itself.
            'unclassified' => throw new RuntimeException('The fake provider blew up in an unexpected way.'),

            default => throw new RuntimeException(sprintf('The fake handler has no outcome "%s".', $outcome)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(ProvisioningJob $job): array
    {
        $payload = $job->payload;
        $config = $payload[self::CONFIG_KEY] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * Shaped like a real provider's answer, credentials and all, so that the
     * redaction on the way into the database is exercised every time.
     *
     * @return array<string, mixed>
     */
    private function response(string $remoteJobId): array
    {
        return [
            'fake' => true,
            'upid' => $remoteJobId,
            'node' => 'fake-node-01',
            // A Proxmox-shaped ticket and an authorization header: both are
            // things a real response genuinely carries.
            'ticket' => 'PVE:root@pam:5F3A1B2C::c2VjcmV0LXRpY2tldC12YWx1ZQ==',
            'headers' => ['authorization' => 'Bearer fake-token-abcdef0123456789'],
        ];
    }
}
