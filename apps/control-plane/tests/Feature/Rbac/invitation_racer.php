<?php

declare(strict_types=1);

/*
 * One operator invitation, in its own process, racing its rivals.
 *
 * Run by TwoInvitationsOfOneAddressAtOnceMakeOneOperatorTest, for the reason
 * bootstrap_racer.php exists: two statements on one connection are serialised
 * by definition, so two invitations deciding "is this address an operator's
 * yet?" at once cannot be shown from one process.
 *
 * Arguments: the inviting operator's id, the address, the role to give.
 * Writes one line of JSON: whether the invitation went through, the refusal
 * of the address if it was refused, or what else it threw.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\InviteOperator;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $actor = User::query()->findOrFail($argv[1]);
    $app->make(InviteOperator::class)->execute($actor, $argv[2], 'Racer', [$argv[3]]);
    $outcome = ['invited' => true, 'refusal' => null, 'error' => null];
} catch (ValidationException $e) {
    $outcome = ['invited' => false, 'refusal' => $e->errors(), 'error' => null];
} catch (Throwable $e) {
    $outcome = ['invited' => false, 'refusal' => null, 'error' => $e::class.': '.$e->getMessage()];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
