<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPU devices in the physical estate.
 *
 * A device belongs to a managed machine — the thing an operator classified —
 * not to a compute node, because a GPU is a card in a chassis whether or not
 * that chassis is a hypervisor yet. Passthrough mode and allocation state are
 * typed, and a device is available or it is not: the readiness engine counts
 * available devices and reads zero as blocked on hardware, which on this
 * build is what it reads.
 *
 * Nothing here is inventory invented for a dashboard. A row is written by an
 * operator on the machine's screen, audited, and the machine must have been
 * classified above do_not_touch first, because a device on a machine nobody
 * may touch is not capacity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpu_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('managed_server_id')->constrained('managed_servers')->cascadeOnDelete();

            $table->string('vendor', 64);
            $table->string('model', 128);
            $table->unsignedInteger('vram_mib');
            // The PCI address as the host reports it, e.g. 0000:41:00.0. It
            // is the device's identity on that machine and nowhere else.
            $table->string('pci_address', 32);
            // pci_passthrough | vgpu | mig | none. Not equivalent to each
            // other: a whole card passed through isolates differently from a
            // slice, and a plan that needs one must not be placed on the other.
            $table->string('passthrough_mode', 24);
            // available | allocated | reserved | faulted
            $table->string('allocation_state', 24)->default('available');
            $table->text('notes')->nullable();
            $table->foreignUlid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['managed_server_id', 'pci_address']);
            $table->index(['allocation_state', 'passthrough_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpu_devices');
    }
};
