<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order a WordPress site was bought on.
 *
 * ===========================================================================
 * WHY THE ORDER AND NOT JUST THE SERVICE
 * ===========================================================================
 *
 * Because of when each one exists. A site row is written the moment the
 * customer asks, so their screen has something to show progress against. The
 * service is created later, at settlement, by the ordinary provisioning path —
 * so at the moment the site is created there is no service to point at.
 *
 * Without this column the two never meet: the hosting account gets built, and
 * nothing can work out which WordPress site it was built for. The site sits at
 * "requested" for ever while its account sits there working, which is the
 * exact shape of defect this platform's phases keep finding — every piece
 * present, nothing joined.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table): void {
            $table->foreignUlid('order_id')->nullable()->after('service_id')
                ->constrained('orders')->nullOnDelete();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
