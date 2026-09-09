<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File-level work against a backup: restores of named paths into the
 * machine, and the short-lived links that let one file be downloaded.
 *
 * A file restore has its own row rather than a state on the backup because
 * the archive is read, not consumed: the backup stays restorable throughout,
 * and "is my backup still good" and "did my files come back" must not share
 * one column. A download link stores the hash of its token, never the token;
 * the token travels once, in the URL the customer was handed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_file_restores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('backup_id')->constrained('backups')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUlid('virtual_machine_id')->constrained('virtual_machines')->cascadeOnDelete();
            $table->string('state', 32);
            $table->string('node_name');
            $table->json('paths');
            $table->unsignedInteger('path_count');
            $table->string('provider_task_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->unsignedInteger('poll_count')->default(0);
            $table->timestampsTz();

            $table->index(['virtual_machine_id', 'state']);
            $table->index(['backup_id', 'created_at']);
            $table->index('state');
        });

        Schema::create('backup_file_downloads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('backup_id')->constrained('backups')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('path', 4096);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->foreignUlid('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['customer_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_file_downloads');
        Schema::dropIfExists('backup_file_restores');
    }
};
