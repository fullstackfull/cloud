<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Queries;

use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\SharedHosting\Application\DTOs\HostingPackageChoice;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;

/**
 * The one answer to "which hosting package is this plan sold under?".
 *
 * ---------------------------------------------------------------------------
 * The defect this replaces (F-32)
 * ---------------------------------------------------------------------------
 *
 * Checkout's placement check and the plan change each asked, in its own
 * module:
 *
 *     HostingPackage::query()->where('plan_id', $planId)->first()
 *
 * No `is_active`, no `ORDER BY`. `hosting_packages.slug` is unique and
 * `plan_id` is not; withdrawing a package is `is_active = false` and nothing
 * deletes one. So replacing the package behind a plan leaves two rows naming
 * it, and the query above answered whichever one a scan met first — a fact
 * about the physical order of the table, which a single UPDATE can change.
 * A paid order could open its account on the withdrawn package and report the
 * old quota to a customer who had paid for the new one, and a paid upgrade
 * could tell the panel the legacy package.
 *
 * ---------------------------------------------------------------------------
 * The rule: take the one on sale, or refuse
 * ---------------------------------------------------------------------------
 *
 * Only a package on sale is a candidate. When exactly one is, that is the
 * answer. When more than one is, nothing is chosen. That is the rule
 * {@see LocalPlacementFeasibility} already applies to clusters, IP pools and
 * OS images — in its own words, "With two clusters the platform has no basis
 * for choosing, and picking the first would place a customer's machine by row
 * order." — and it applies here for the same reason. There is deliberately no
 * `ORDER BY` below. An ordering would be
 * a tiebreak, and it rests on an assumption stated here rather than hidden in
 * a sort: **nothing in this repository says which of two packages on sale a
 * plan sells** — not the newest, not the largest, not the one an operator
 * mapped last — so no tiebreak is invented. Two on sale is a configuration an
 * operator has to finish, and the refusal says so.
 *
 * The refusals are three because they send an operator to three different
 * places, and they are told apart on a table that is never assumed empty:
 *
 *   - NAMES_NONE: no package names this plan at all — map one.
 *   - ALL_WITHDRAWN: packages name it and every one has been withdrawn — put
 *     one back on sale, or map a replacement.
 *   - NAMES_SEVERAL: more than one is on sale — withdraw all but one.
 *
 * A null or empty plan id names no package, and is refused before the database
 * is asked. The empty string is not harmless to pass through: the schema will
 * hold a plan whose id is blank and a package filed under it, and
 * `plan_id = ''` finds that package. On this `character(26)` column '' and
 * twenty-six blanks are the same value, so it would find it however the blank
 * had been written.
 *
 * ---------------------------------------------------------------------------
 * Nothing else may choose
 * ---------------------------------------------------------------------------
 *
 * `OnlyOneResolverChoosesAHostingPackageForAPlanTest` walks every PHP file
 * under `src/`, `app/` and `database/`, with comments stripped, and fails on
 * any other expression it recognises that narrows hosting packages by a plan
 * id and takes one row out of the result. What it recognises, and the
 * spellings it cannot see, are tables in that test, each asserted.
 *
 * Callers: {@see LocalPlacementFeasibility} — which checkout, the payment-time
 * recheck and the build all resolve through — and
 * {@see QueuePlanChangeAtProvider}.
 */
final readonly class HostingPackageForPlan
{
    public const string NAMES_NONE = 'names_none';

    public const string ALL_WITHDRAWN = 'all_withdrawn';

    public const string NAMES_SEVERAL = 'names_several';

    public function resolve(?string $planId): HostingPackageChoice
    {
        if ($planId === null || $planId === '') {
            return self::refuse(self::NAMES_NONE);
        }

        // Two rows are enough to know there is more than one; the rest of
        // them would change nothing.
        /** @var list<HostingPackage> $onSale */
        $onSale = HostingPackage::query()
            ->where('plan_id', $planId)
            ->where('is_active', true)
            ->limit(2)
            ->get()
            ->all();

        if (count($onSale) === 1) {
            return HostingPackageChoice::of($onSale[0]);
        }

        if ($onSale !== []) {
            return self::refuse(self::NAMES_SEVERAL);
        }

        $withdrawn = HostingPackage::query()
            ->where('plan_id', $planId)
            ->exists();

        return self::refuse($withdrawn ? self::ALL_WITHDRAWN : self::NAMES_NONE);
    }

    private static function refuse(string $refusal): HostingPackageChoice
    {
        return HostingPackageChoice::refused($refusal, match ($refusal) {
            // The sentence the blocked-service screen has always shown.
            self::NAMES_NONE => 'the plan names no hosting package, so no panel quota can be applied',
            self::ALL_WITHDRAWN => 'every hosting package mapped to the plan has been withdrawn, so no panel quota can be applied',
            default => 'the plan names more than one hosting package on sale, and nothing says which of them it sells',
        });
    }
}
