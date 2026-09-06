<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity: users, their profiles, two-factor state and login history.
 *
 * All primary keys are ULIDs: they are lexicographically sortable (so they
 * index well as primary keys, unlike UUIDv4) and safe to expose in URLs and
 * API responses, unlike sequential integers which leak customer counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('locale', 5)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('phone', 32)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();

            // Two-factor authentication. The secret and recovery codes are
            // encrypted at rest by the model's cast, never stored in clear.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            // Account safety controls.
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestampTz('password_changed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('locked_until');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index()
                ->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Append-only record of authentication outcomes. Kept separate from the
        // audit log because it is high-volume and has a different retention
        // policy, and because a customer can view their own login history.
        Schema::create('login_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Recorded even for failures, where user_id may be null because the
            // address did not match an account.
            $table->string('email_attempted')->nullable();
            $table->string('outcome', 32);              // success | failed | locked | two_factor_failed
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('country', 2)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['outcome', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activities');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
