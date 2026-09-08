<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What became of the provider task a job handed off to.
 *
 * `remote_job_id` has been recorded since Phase 6 and nothing ever asked the
 * provider about it. On Proxmox that identifier is a UPID: the API answers a
 * create in milliseconds with a handle, and the machine is built minutes
 * later — so a job marked succeeded means "the hypervisor accepted the
 * request", which is not the same sentence as "the machine exists".
 *
 * The columns live on the job rather than in a table of their own. The job is
 * already the row that owns the handle, knows the service, carries the
 * failure vocabulary and has an event for needing a person; a separate task
 * table would be a second place to look for the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            /*
             * Which node to ask. A task handle means nothing without it —
             * Proxmox answers per node — and the job's payload does not
             * reliably carry one, because several kinds place the machine
             * themselves.
             */
            $table->string('remote_task_node')->nullable()->after('remote_job_id');

            // Null until somebody has asked and been given a terminal answer.
            $table->string('remote_task_state', 16)->nullable()->after('remote_task_node');

            $table->timestamp('remote_task_polled_at')->nullable()->after('remote_task_state');
            $table->unsignedSmallInteger('remote_task_poll_count')->default(0)->after('remote_task_polled_at');

            // The sweep's only query.
            $table->index(['status', 'remote_task_state', 'remote_task_polled_at'], 'provisioning_jobs_task_sweep');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->dropIndex('provisioning_jobs_task_sweep');
            $table->dropColumn([
                'remote_task_node',
                'remote_task_state',
                'remote_task_polled_at',
                'remote_task_poll_count',
            ]);
        });
    }
};
