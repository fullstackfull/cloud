<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One service per purchased line, enforced by the database.
 *
 * Fulfilment now creates the service a customer bought, and fulfilment runs
 * from an event that can be delivered more than once — a redelivered webhook, a
 * retried job, an operator re-running a settlement. Checking for an existing
 * row before inserting is necessary and not sufficient: two workers can both
 * check, both find nothing, and both insert, and the customer ends up with two
 * machines and one subscription paying for them.
 *
 * The column is nullable because a service may exist without an order behind it
 * — a migration from another platform, an account an operator stood up by hand
 * — and PostgreSQL does not consider two NULLs equal, so those rows are
 * unaffected by this constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->unique('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropUnique(['order_item_id']);
        });
    }
};
