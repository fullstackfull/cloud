<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which storage a machine's disk was created on.
 *
 * The platform chose it — the scheduler picks a node *and* a storage, weighing
 * the customer's storage class against what each node actually has — and then
 * threw the answer away the moment the machine was built. Nothing needed it
 * again until a reinstall, which has to create the replacement disk somewhere
 * and has exactly two options: put it where the old one was, or guess.
 *
 * Guessing is not a small thing. The storages on a node are different tiers —
 * NVMe, SSD, spinning, Ceph — so a guess silently moves a customer's server
 * onto hardware they did not buy, and moves the platform's capacity accounting
 * with it. A machine whose storage is unknown therefore refuses to be
 * reinstalled rather than being rebuilt somewhere plausible.
 *
 * Nullable because rows written before this column existed genuinely do not
 * know, and inventing a value for them would be the same guess with a
 * migration's authority behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->string('storage_name', 64)->nullable()->after('provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->dropColumn('storage_name');
        });
    }
};
