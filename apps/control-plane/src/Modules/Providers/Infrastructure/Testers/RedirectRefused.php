<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use RuntimeException;

/**
 * A provider API answered with a redirect, so the test stopped.
 *
 * Not an error condition to be smoothed over. A Location header is chosen by
 * whatever answered the request, and following one has two separate
 * consequences, both bad: the credential is re-sent to a host somebody else
 * named, and an impostor gets to borrow a real product's response to pass the
 * identity check with.
 *
 * It is also, very often, simply the wrong address: a panel's web interface
 * redirecting an API path to a login page, or a plain HTTP endpoint bouncing to
 * HTTPS somewhere else entirely. Both are worth telling an operator about
 * precisely, rather than after a redirect chain has hidden them.
 */
final class RedirectRefused extends RuntimeException
{
    public static function at(string $path, int $status): self
    {
        return new self(sprintf(
            'the endpoint answered %s with an HTTP %d redirect. A provider API answers where it is asked; '
            .'a redirect is not followed, because following one would re-send the credential to whatever host the '
            .'redirect names and would let that host answer for this one.',
            $path,
            $status,
        ));
    }
}
