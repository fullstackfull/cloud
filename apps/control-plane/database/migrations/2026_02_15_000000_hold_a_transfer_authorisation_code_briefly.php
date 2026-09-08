<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep a transfer's authorisation code between paying and sending.
 *
 * ===========================================================================
 * WHY THIS COLUMN EXISTS, GIVEN THAT IT SHOULD NOT
 * ===========================================================================
 *
 * An authorisation code is a bearer credential for an entire domain: whoever
 * holds it can move the name. The rule everywhere else in this module is that
 * it is never stored — it arrives, it is sent, it goes out of scope.
 *
 * A transfer breaks that rule for a few minutes, and there is no design that
 * avoids it. The customer supplies the code when they order; the registrar
 * cannot be asked until the invoice is paid, because a transfer costs money
 * and includes a year. Between those two moments the code has to live
 * somewhere. The alternatives are worse: asking the customer for it a second
 * time after payment strands every transfer whose customer has closed the tab,
 * and starting the transfer before payment buys a year on the platform's card.
 *
 * So it is kept, under the same encryption as the registrant's address, and
 * erased the moment the transfer is sent — success or failure. What is left
 * behind is a null column and an operation row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_operations', function (Blueprint $table): void {
            // Text rather than string: the ciphertext of a short code is not
            // short, and a length limit here is a truncated credential.
            $table->text('authorisation_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('domain_operations', function (Blueprint $table): void {
            $table->dropColumn('authorisation_code');
        });
    }
};
