<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subject type is a class name, and class names are longer than 64.
 *
 * The audit table was created with `varchar(64)` for subject_type, on the
 * assumption that it would hold something short. It holds whatever
 * `getMorphClass()` returns, which with no morph map configured is the
 * fully-qualified name — and this project's namespaces are deep enough that
 * the very first subject written, a provisioning job at 66 characters, did not
 * fit.
 *
 * The failure mode is the one that matters here: the insert throws, so an
 * operator's adoption of an orphaned machine returned a 500 *after* the
 * adoption had already been recorded against the job. The act happened and its
 * audit row did not, which is the exact hole the table exists to close.
 *
 * 191 rather than 255: long enough for any namespace this codebase will grow,
 * short enough to stay indexable under any MySQL collation should the platform
 * ever run on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->string('subject_type', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->string('subject_type', 64)->nullable()->change();
        });
    }
};
