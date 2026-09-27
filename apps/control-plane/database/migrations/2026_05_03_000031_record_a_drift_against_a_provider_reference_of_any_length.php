<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A drift is recorded against whatever the provider calls the resource, however
 * long that is.
 *
 * `resource_drifts.provider_reference` was `varchar(255)`. The hosting sweep
 * records an account the panel lists and the platform does not know under the
 * name the panel gave it, and neither adapter bounds that name's length: a
 * name longer than the platform would make is read as that account on
 * purpose, so that an operator sees it. A listed name over 255 characters made
 * the insert fail (SQLSTATE 22001), and the sweep stopped on that node every
 * run.
 *
 * Widened to `text` rather than refused or shortened. Refusing the name would
 * make the node's whole listing unreadable over one odd account, and a hash or
 * a truncation would record a drift the operator cannot match to anything on
 * the panel. Nothing indexes the column, and in PostgreSQL varchar to text is
 * a change of type the table is not rewritten for; every existing value stays
 * as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_drifts', function (Blueprint $table): void {
            $table->text('provider_reference')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fails, and says so, if a reference longer than 255 characters has
        // been recorded since: shortening it would lose what it names.
        Schema::table('resource_drifts', function (Blueprint $table): void {
            $table->string('provider_reference')->nullable()->change();
        });
    }
};
