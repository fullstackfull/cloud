<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use RuntimeException;

/**
 * Something about a catalogue change was refused.
 *
 * These are read by operators over HTTP. None of them contains anything the
 * caller did not already supply, and none of them contains money the caller is
 * not already looking at — a price is not a secret, but a refusal that echoed
 * an unrelated row's amount would still be leaking one account's commercial
 * terms into another's screen.
 */
final class CatalogueRefused extends RuntimeException
{
    /**
     * @param  list<string>  $supported
     */
    public static function unknownProductKind(string $kind, array $supported): self
    {
        return new self(sprintf(
            '"%s" is not a product this platform can deliver. The kinds it can are %s, and that '
            .'list is the software rather than a setting: a kind with no provisioning path behind '
            .'it is an order nobody can fulfil. Products outside it exist as readiness rows, not '
            .'as things to sell.',
            $kind,
            implode(', ', $supported),
        ));
    }

    public static function planBelongsToNoProduct(string $product): self
    {
        return new self(sprintf(
            'There is no product %s to put this plan under. A plan is how one product is sold, so '
            .'it cannot exist beside the catalogue.',
            $product,
        ));
    }

    public static function computePlanIsUnderspecified(string $key): self
    {
        return new self(sprintf(
            'This plan sells a virtual machine and says nothing about its %s. A build reads that '
            .'value with a default behind it, so the plan would not fail — it would quietly build '
            .'the smallest machine and invoice whatever the price said. Say what is being sold.',
            $key,
        ));
    }

    public static function dedicatedPlanNamesNoHardwareProfile(): self
    {
        return new self(
            'This plan sells a dedicated server and names no hardware profile. The reservation '
            .'would look for a machine matching nothing and report no capacity, which is retried '
            .'rather than refused — so the order waits for a rack that was never the problem.',
        );
    }

    public static function priceIsNotAnInteger(string $amount): self
    {
        return new self(sprintf(
            '%s is not an amount in minor units. Money is carried here as whole minor units — fils, '
            .'cents — because a decimal is a rounding decision taken by whichever layer parses it '
            .'last, and the layer that parses it last is usually the one nobody tested.',
            $amount,
        ));
    }

    public static function priceIsNegative(string $field): self
    {
        return new self(sprintf(
            'A %s below zero is not a discount, it is a refund the billing engine never agreed to '
            .'issue. Discounts are coupons, and a free plan is priced at zero.',
            $field,
        ));
    }

    public static function availabilityEndsBeforeItStarts(): self
    {
        return new self(
            'That price stops being available before it starts. A window that never opens is a plan '
            .'nobody can buy in that currency, reported as configured.',
        );
    }

    public static function hostingPackageBelongsToNoPlan(string $plan): self
    {
        return new self(sprintf(
            'There is no plan %s for this hosting package to serve. The mapping exists so that a '
            .'plan resolves to one package on the panel; a mapping with no plan resolves nothing.',
            $plan,
        ));
    }

    public static function hostingPackageIsNotForHosting(string $plan, ProductKind $kind): self
    {
        return new self(sprintf(
            'Plan %s sells a %s, and a hosting package is what a shared hosting plan is delivered '
            .'as. Mapping one onto another kind would put a panel package behind an order the '
            .'panel never sees.',
            $plan,
            $kind->value,
        ));
    }
}
