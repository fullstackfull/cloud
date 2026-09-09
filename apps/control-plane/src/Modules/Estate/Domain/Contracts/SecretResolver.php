<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\Contracts;

/**
 * The one place a secret is turned from a reference into a value.
 *
 * Everything else in the control centre handles references. This is the single
 * seam where the value exists, it is called as late as possible, and the value
 * it returns goes straight into a TestTarget and dies with it.
 *
 * The interface exists so that the backend can change — controller
 * environment today, a vault later — without any calling code learning that it
 * did, and so that a test can substitute a resolver without a real secret ever
 * being involved.
 */
interface SecretResolver
{
    /**
     * The secret behind a reference, or null when the backend does not have it.
     *
     * Null is an ordinary answer and means "not configured". It is not an
     * error, because a credential that has been declared and not yet populated
     * is exactly the state the control centre exists to display.
     */
    public function resolve(string $backend, string $reference): ?string;
}
