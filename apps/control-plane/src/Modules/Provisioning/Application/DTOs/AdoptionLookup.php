<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\DTOs;

/**
 * What an adoption asked the provider before it took any lock
 * (AdoptOrphanResource::lookFor()), for the adoption to act on under them.
 *
 * The answer is ReservationsFollowAnAdoption::lookFor()'s, opaque here: only
 * the module that asked reads it.
 *
 * @immutable
 */
final readonly class AdoptionLookup
{
    public function __construct(
        public string $jobId,
        public string $providerReference,
        public mixed $answer,
    ) {}
}
