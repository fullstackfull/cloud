<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Illuminate\Support\Str;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPasswordResetRefusedException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Set a new panel password on a hosting account, and hand it back once.
 *
 * ---------------------------------------------------------------------------
 * The hole this fills
 * ---------------------------------------------------------------------------
 *
 * `HostingProvider::changePassword()` was implemented by every adapter and
 * called by nothing (F-04's last clause). That mattered because of a choice
 * the platform makes on purpose: every build mints its own password and keeps
 * none of it (see CreateHostingAccountHandler::PASSWORD_LENGTH). So an attempt
 * whose `createacct` answer was lost leaves the panel holding a live account
 * under a credential nobody has — the row pending and occupying its slot, so
 * nothing releases or terminates it, and no column, job or audit entry holding
 * the value. A customer reaches the panel by single sign-on and never needs
 * it; an operator who has to get into that account, or hand it over, had no
 * way to set one.
 *
 * ---------------------------------------------------------------------------
 * The decisions
 * ---------------------------------------------------------------------------
 *
 * GENERATED HERE, NOT ACCEPTED. A password in a request body crosses the
 * inbound boundary, where nothing redacts a request the way `RedactedJsonCast`
 * redacts a column, and it would be whatever a person under pressure types.
 * Generating it means no password ever arrives at this API.
 *
 * STORED NOWHERE. Not on the row, not on a job, not in the audit context, not
 * in a log. It is returned to the caller once and that is the only copy; the
 * reasoning `CreateAccountRequest` gives for the build is untouched.
 *
 * ALLOWED ON PENDING. Pending is the lost-answer state; refusing it would be
 * refusing the case this exists for. Only an account the panel no longer
 * holds — terminated or failed — is refused, from the platform's own record
 * and without asking the panel.
 *
 * AN OPERATOR ACT. The permission, the audit entry and the ordering (panel
 * first, then the record) are the controller's; this action only decides
 * whether a reset may be attempted and performs it.
 */
final readonly class ResetHostingAccountPassword
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @return string the password now set at the panel, which the caller must not keep
     *
     * @throws HostingPasswordResetRefusedException
     * @throws HostingProviderException
     */
    public function execute(HostingAccount $account): string
    {
        if (! $account->status->existsAtPanel()) {
            throw HostingPasswordResetRefusedException::becauseThePanelNoLongerHoldsIt(
                (string) $account->getKey(),
                $account->status->value,
            );
        }

        $node = $account->node()->firstOrFail();

        $password = Str::password(CreateHostingAccountHandler::PASSWORD_LENGTH, symbols: false);

        $this->providers->for($node)->changePassword($node, $account->username, $password);

        return $password;
    }
}
