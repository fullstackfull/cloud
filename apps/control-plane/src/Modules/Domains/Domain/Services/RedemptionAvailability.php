<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Services;

use Lynomia\Modules\Domains\Domain\DTOs\RedemptionAnswer;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\RedemptionSupport;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\UnknownRegistrarDriverException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;

/**
 * Can a name in this namespace be recovered here, and what does it cost?
 *
 * Three things have to be true, checked in the order a person would check
 * them: the registrar has an integration at all; the registrar says it can
 * redeem (a registrar that has never said — `.sy` — is `unknown`, and the
 * platform does not turn silence into yes); and the catalogue row for the
 * namespace carries the registry's penalty and its windows, because a
 * platform that quotes a redemption fee it was never told is quoting a
 * guess somebody has to pay.
 *
 * No network call: every answer here is a fact the platform already holds.
 * The registrar is asked to DO something only after the money has moved.
 */
final readonly class RedemptionAvailability
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
    ) {}

    public function forTld(DomainTld $tld): RedemptionAnswer
    {
        try {
            $registrar = $this->registrars->make($tld->provider);
        } catch (UnknownRegistrarDriverException) {
            return RedemptionAnswer::unavailable(
                RedemptionSupport::BlockedConfiguration,
                sprintf('No registrar integration is configured for .%s.', $tld->tld),
            );
        }

        $support = $registrar->redemptionSupport();

        if ($support !== RedemptionSupport::Supported) {
            return RedemptionAnswer::unavailable($support, match ($support) {
                RedemptionSupport::Unsupported => sprintf('The registrar for .%s cannot recover names from redemption.', $tld->tld),
                RedemptionSupport::Unknown => sprintf('The registry for .%s has not published how, or whether, a lapsed name can be recovered. Nothing is offered until it does.', $tld->tld),
                RedemptionSupport::BlockedConfiguration => sprintf('The registrar for .%s is not configured for redemption.', $tld->tld),
            });
        }

        if (! $registrar->supports(RegistrarCapability::Redemption)) {
            return RedemptionAnswer::unavailable(
                RedemptionSupport::Unsupported,
                sprintf('The registrar for .%s does not offer redemption.', $tld->tld),
            );
        }

        if ($tld->grace_days === null || $tld->redemption_days === null) {
            return RedemptionAnswer::unavailable(
                RedemptionSupport::BlockedConfiguration,
                sprintf('The grace and redemption windows for .%s have not been recorded, so the platform cannot say whether a name is still recoverable.', $tld->tld),
            );
        }

        $price = $tld->priceFor(DomainOperationKind::Redeem);

        if ($price === null || ! $tld->permits(DomainOperationKind::Redeem)) {
            return RedemptionAnswer::unavailable(
                RedemptionSupport::BlockedConfiguration,
                sprintf('The registry\'s redemption penalty for .%s has not been recorded, and the platform will not quote a guess.', $tld->tld),
            );
        }

        return RedemptionAnswer::available($price);
    }
}
