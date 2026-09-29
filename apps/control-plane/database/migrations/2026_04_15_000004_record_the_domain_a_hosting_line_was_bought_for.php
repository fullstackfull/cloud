<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domain a shared-hosting line was bought for.
 *
 * A hosting account is built for a name, and until this column there was
 * nowhere for that name to come from: checkout asked for a plan and a
 * quantity, fulfilment built the job from the plan's resources, and every
 * order reached the control panel as `<username>.hosting.invalid` (F-04).
 *
 * Recorded on the line rather than on the order because a basket can hold
 * more than one hosting plan, each for its own name, and because the line is
 * already the unit fulfilment works in — one service per line.
 *
 * Nullable, and it stays nullable: a VPS or a dedicated line has no domain,
 * and every line written before this column existed has none either. The
 * value is stored folded (see `DnsName::canonicalAsSubmitted()`), so what was
 * bought and what is built compare as bytes.
 *
 * 253 is RFC 1035's ceiling on a name without its root dot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('domain', 253)->nullable()->after('resources_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('domain');
        });
    }
};
