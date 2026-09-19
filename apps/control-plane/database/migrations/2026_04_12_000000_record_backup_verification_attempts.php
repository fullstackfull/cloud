<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the verification sweep needs in order to be idempotent.
 *
 * `startVerification` existed on the provider contract and on both drivers,
 * `Verifying` was a state with transitions out of it, and nothing in the
 * platform ever put a row into it — so `verified` was null for every backup
 * the platform had ever taken. The sweep that closes that has to be safe to
 * run every five minutes for ever, which needs two things the table did not
 * have.
 *
 * `verification_requested_at` is the fair-ordering key, the same shape as
 * `last_polled_at` on the polling sweep: least recently asked about, asked
 * first.
 *
 * `verification_attempts` is the back-off. A datastore that refuses a
 * verification refuses it again a minute later, and a sweep with no memory
 * would ask a broken provider several hundred times an hour for ever. After
 * the configured number of attempts the archive is left for a person, which is
 * the same shape as every other give-up in this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->timestamp('verification_requested_at')->nullable()->after('verification_task_id');
            $table->unsignedSmallInteger('verification_attempts')->default(0)->after('verification_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn(['verification_requested_at', 'verification_attempts']);
        });
    }
};
