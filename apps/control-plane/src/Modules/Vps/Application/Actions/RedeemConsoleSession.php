<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Actions;

use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\Exceptions\ConsoleSessionInvalidException;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * Spend a console permit, once.
 *
 * The console gateway's entry point, not the customer's — no HTTP route on the
 * customer surface reaches this, and the gateway process itself is not part of
 * this module. It exists here anyway because "single-use" is a claim about
 * behaviour, and a claim nothing can execute is a claim nothing tests. With
 * this action the property is provable: redeem once and you get the machine
 * id, redeem again and you get a refusal, whatever the token was.
 *
 * Everything that can be wrong answers identically. Expired, unknown, already
 * spent and wrong token are one exception with one message, because a gateway
 * that distinguished them would tell anybody who can reach it which session
 * ids are live.
 */
final readonly class RedeemConsoleSession
{
    public function __construct(
        private ConsoleSessionStore $sessions,
    ) {}

    /**
     * @throws ConsoleSessionInvalidException
     */
    public function execute(string $sessionId, string $token): ConsoleSession
    {
        $session = $this->sessions->consume($sessionId, $token);

        if ($session === null) {
            throw ConsoleSessionInvalidException::make();
        }

        return $session;
    }
}
