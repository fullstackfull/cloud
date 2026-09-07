<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a token was used from.
 *
 * The platform revokes a token by recording the revocation rather than deleting
 * the row, and the reason given for that — in the model, in the action and in
 * the API resource — is that "which token did this, and from where?" must stay
 * answerable after the token is gone. That reason only holds if the address is
 * actually written. It was not: the column existed, the resource published it,
 * three docblocks leaned on it, and nothing anywhere set it.
 */
final class TokenUsageIsRecordedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Route::middleware(['api', 'auth:sanctum'])
            ->get('/api/test/token-usage', static fn (): array => ['ok' => true]);
    }

    private function tokenFor(Customer $customer): string
    {
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $token = $user->createToken('test');
        $token->accessToken->forceFill(['customer_id' => $customer->id])->save();

        return $token->plainTextToken;
    }

    #[Test]
    public function the_calling_address_is_recorded_on_the_token(): void
    {
        $customer = Customer::factory()->create();
        $plain = $this->tokenFor($customer);

        $this->withToken($plain)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/api/test/token-usage')
            ->assertOk();

        $this->assertSame(
            '198.51.100.7',
            PersonalAccessToken::query()->sole()->last_used_ip,
        );
    }

    #[Test]
    public function a_move_to_a_new_address_is_visible(): void
    {
        $customer = Customer::factory()->create();
        $plain = $this->tokenFor($customer);

        foreach (['198.51.100.7', '198.51.100.7', '203.0.113.19'] as $address) {
            // The test client does not reboot the application between calls, so
            // the guard would otherwise serve the user it resolved the first
            // time and Sanctum's callback would never run again. A real second
            // request has no such memory.
            Auth::forgetGuards();

            $this->withToken($plain)
                ->withServerVariables(['REMOTE_ADDR' => $address])
                ->getJson('/api/test/token-usage')
                ->assertOk();
        }

        // The point of the column is noticing that a token started being used
        // from somewhere else. The last address wins.
        $this->assertSame(
            '203.0.113.19',
            PersonalAccessToken::query()->sole()->last_used_ip,
        );
    }

    #[Test]
    public function the_address_survives_revocation(): void
    {
        $customer = Customer::factory()->create();
        $plain = $this->tokenFor($customer);

        $this->withToken($plain)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/api/test/token-usage')
            ->assertOk();

        $token = PersonalAccessToken::query()->sole();
        $token->revoke('compromised');

        // Revocation records rather than deletes precisely so this survives.
        $revoked = PersonalAccessToken::query()->sole();

        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('198.51.100.7', $revoked->last_used_ip);

        // And the revoked token no longer authenticates.
        Auth::forgetGuards();

        $this->withToken($plain)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/api/test/token-usage')
            ->assertUnauthorized();
    }
}
