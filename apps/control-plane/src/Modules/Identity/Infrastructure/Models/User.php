<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\HasApiTokens;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Spatie\Permission\Traits\HasRoles;

/**
 * A login. Not a commercial entity — see Customer for that.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ?CarbonImmutable $email_verified_at
 * @property ?string $two_factor_secret
 * @property ?array<int, string> $two_factor_recovery_codes
 * @property ?CarbonImmutable $two_factor_confirmed_at
 * @property int $failed_login_attempts
 * @property ?CarbonImmutable $locked_until
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes;

    /**
     * Number of consecutive failures before the account is temporarily locked.
     * Locking is time-based rather than permanent so that an attacker cannot
     * deny a legitimate user access indefinitely by guessing their password.
     */
    public const int MAX_FAILED_LOGIN_ATTEMPTS = 5;

    public const int LOCKOUT_MINUTES = 15;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'password' => 'hashed',

            // Encrypted at rest: a database dump must not yield working TOTP
            // secrets or usable recovery codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',

            'failed_login_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<Customer, $this, CustomerMember>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_members')
            ->using(CustomerMember::class)
            ->withPivot(['id', 'role', 'accepted_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CustomerMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMember::class);
    }

    /**
     * @return HasMany<LoginActivity, $this>
     */
    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Record a failed authentication attempt, locking the account once the
     * threshold is crossed. Returns true when this attempt caused a lock.
     */
    public function registerFailedLogin(): bool
    {
        $this->failed_login_attempts++;

        if ($this->failed_login_attempts >= self::MAX_FAILED_LOGIN_ATTEMPTS) {
            $this->locked_until = Date::now()->addMinutes(self::LOCKOUT_MINUTES);
            $this->failed_login_attempts = 0;
            $this->save();

            return true;
        }

        $this->save();

        return false;
    }

    public function registerSuccessfulLogin(?string $ipAddress): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => Date::now(),
            'last_login_ip' => $ipAddress,
        ])->save();
    }

    /**
     * The role this user holds inside a given customer account, or null when
     * they are not a member of it.
     */
    public function roleWithin(string $customerId): ?CustomerRole
    {
        $membership = $this->memberships
            ->firstWhere('customer_id', $customerId);

        return $membership?->role;
    }
}
