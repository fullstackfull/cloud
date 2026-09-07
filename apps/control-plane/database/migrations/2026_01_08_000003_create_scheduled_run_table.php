<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each scheduled command last ran, and last succeeded.
 *
 * The platform's most important work is scheduled: renewals, dunning, drift
 * detection, address reclamation, payment reconciliation. All of it runs
 * unattended, and the failure that costs the most is not a command that
 * errors — it is a command that stops being invoked at all. A crashed
 * scheduler, a cron entry lost in a redeploy, a container that never restarts:
 * every one of those is silent. Nothing errors, no alert fires, and the first
 * symptom is a customer whose subscription was never renewed.
 *
 * One row per command, updated in place. This is a liveness signal, not a
 * history: an append-only run log would grow by nine rows an hour for ever to
 * answer a question that only ever concerns the most recent run, and the
 * scheduler already writes its output to a log file for the times somebody
 * needs the detail.
 *
 * `last_succeeded_at` is separate from `last_ran_at` on purpose. A command
 * that runs every five minutes and fails every time keeps its `last_ran_at`
 * fresh, and an alert built on that would stay green through a total outage of
 * the thing being watched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The command as the scheduler names it. Bounded by the number of
            // entries in routes/console.php, which is what makes it safe to
            // use as a metric label.
            $table->string('command', 191)->unique();

            $table->timestampTz('last_ran_at')->nullable();
            $table->timestampTz('last_succeeded_at')->nullable();
            $table->timestampTz('last_failed_at')->nullable();

            $table->unsignedInteger('consecutive_failures')->default(0);

            // Truncated by the writer. This is a signal, not a log: the full
            // output goes to storage/logs/schedule.log.
            $table->string('last_failure', 500)->nullable();

            $table->unsignedInteger('last_runtime_ms')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_runs');
    }
};
