<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;

/**
 * A panel session was asked for on an account that cannot have one.
 *
 * Refused here rather than left to the panel, because the panel's refusal
 * arrives as a provider failure — a 502 that says the platform could not talk
 * to a control panel — and that is the wrong answer three times over: it
 * blames the platform for a state the customer's own account is in, it hides a
 * suspension behind an outage, and it puts a call onto a node for an account
 * that has nothing to log into.
 *
 * The states are answered separately because they mean different things to
 * the person reading them. Pending is "wait"; suspended is "settle the
 * invoice"; terminated is "there is nothing left".
 */
final class HostingPanelSessionUnavailableException extends DomainException
{
    public static function notReady(string $accountId, HostingAccountStatus $status): self
    {
        $exception = new self(
            'This hosting account is still being set up on its node, so there is nothing to sign in to yet.',
        );

        return $exception->withContext([
            'account_id' => $accountId,
            'status' => $status->value,
        ]);
    }

    public static function suspended(string $accountId): self
    {
        $exception = new self(
            'This hosting account is suspended. Its data is preserved, but the panel will not accept a '
            .'sign-in until the suspension is lifted.',
        );

        return $exception->withContext([
            'account_id' => $accountId,
            'status' => HostingAccountStatus::Suspended->value,
        ]);
    }

    public static function gone(string $accountId, HostingAccountStatus $status): self
    {
        $exception = new self('This hosting account no longer exists on its node.');

        return $exception->withContext([
            'account_id' => $accountId,
            'status' => $status->value,
        ]);
    }

    /**
     * The node itself is out of service.
     *
     * Deliberately says nothing about which node, or how many other accounts
     * are on it. A customer learns that their own hosting is unreachable; they
     * do not learn the name of the machine or that their neighbours are down
     * too.
     */
    public static function nodeOffline(string $accountId): self
    {
        $exception = new self(
            'The server this hosting account lives on is not currently serving sign-ins. It is being '
            .'worked on; your data is untouched.',
        );

        return $exception->withContext(['account_id' => $accountId]);
    }

    public function errorCode(): string
    {
        return 'hosting.panel_session_unavailable';
    }

    /**
     * 409: the request is well formed and the caller is entitled to make it —
     * the account is simply in a state that has no panel session in it.
     */
    public function httpStatus(): int
    {
        return 409;
    }
}
