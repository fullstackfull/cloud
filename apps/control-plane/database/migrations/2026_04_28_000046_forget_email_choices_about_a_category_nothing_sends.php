<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "operational" notification category is gone, so the choices people
 * stored about it go too.
 *
 * ---------------------------------------------------------------------------
 * Why the category went
 * ---------------------------------------------------------------------------
 *
 * It held two notification types, an incident affecting a service and planned
 * maintenance, and nothing in the platform raised either: there is no record
 * of an incident and no schedule of maintenance to raise them from. F-46
 * deleted both, and a category with no types in it is a switch on the
 * preferences screen that controls nothing.
 *
 * ---------------------------------------------------------------------------
 * Why the rows cannot simply be left
 * ---------------------------------------------------------------------------
 *
 * `notification_preferences.category` is cast to the NotificationCategory
 * enum. A stored `operational` row — written by anybody who switched those
 * emails off, which the screen allowed — no longer names a case, so reading it
 * throws, and the preferences screen of exactly the people who used it would
 * fail. Deleting the rows loses nothing: a preference about messages that are
 * never sent has no effect to preserve, and a missing row already means
 * "enabled" for every category that does exist.
 *
 * Only this category's rows are touched. `down()` restores nothing, because
 * there is nothing a restored row could do: the case it names does not exist
 * in the code that would read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_preferences')
            ->where('category', 'operational')
            ->delete();
    }

    public function down(): void
    {
        // Deliberately nothing; see the class docblock.
    }
};
