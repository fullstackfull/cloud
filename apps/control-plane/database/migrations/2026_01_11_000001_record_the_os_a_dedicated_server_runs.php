<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which install profile a physical machine is actually running.
 *
 * The platform chose it at provisioning time, wrote it into a PXE
 * authorisation, and then had nowhere to keep it. Every later question —
 * what is this machine running, what does "reinstall with the same image"
 * mean, which customers are on a profile we are about to withdraw — had to be
 * answered by digging through boot authorisations, which is a record of what
 * was *attempted* rather than of what is installed.
 *
 * Nullable, and deliberately not backfilled: a machine racked before this
 * column existed genuinely does not know, and inventing an answer would give
 * a reinstall a default that nobody chose. Such a machine can still be rebuilt
 * — the customer names the profile — it just has no "the same again".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dedicated_servers', function (Blueprint $table): void {
            $table->foreignUlid('os_install_profile_id')
                ->nullable()
                ->after('hardware_profile')
                ->constrained('os_install_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dedicated_servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('os_install_profile_id');
        });
    }
};
