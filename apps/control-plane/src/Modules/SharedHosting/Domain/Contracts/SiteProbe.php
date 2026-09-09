<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Contracts;

use Lynomia\Modules\SharedHosting\Domain\DTOs\SiteProbeResult;

/**
 * Looking at a site the way a visitor would.
 *
 * This exists because "the installer said it worked" is not the same claim as
 * "the site works", and only one of them is worth putting on a customer's
 * screen. Installers return success for sites that then serve a blank page, a
 * database error, or the panel's holding page — and the customer finds out by
 * visiting, which is the worst possible moment.
 *
 * Behind an interface because it is the one thing on this platform that makes
 * an outbound request to an address a customer chose, and a test suite must be
 * able to answer for it without a network.
 */
interface SiteProbe
{
    public function probe(string $url): SiteProbeResult;
}
