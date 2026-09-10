<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The language a request asks for is the language it is answered in.
 *
 * Every arm of the exception renderer, the validator and the localised
 * resources read the application locale, and the SetRequestLocale middleware
 * sets it from `Accept-Language` before any of them run. These tests drive
 * real customer routes rather than the middleware in isolation, because the
 * claim is about what the customer reads, not about a unit.
 */
final class RequestLocaleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_customer_route_answers_a_refusal_in_the_language_the_request_asked_for(): void
    {
        $user = User::factory()->create();

        $english = $this->actingAs($user)
            ->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/vps/01J00000000000000000000000/power', ['action' => 'start']);

        $this->flushHeaders();

        $arabic = $this->actingAs($user)
            ->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/vps/01J00000000000000000000000/power', ['action' => 'start']);

        // The same code either way — clients branch on it — and a different sentence.
        $this->assertSame($english->json('error.code'), $arabic->json('error.code'));
        $this->assertNotSame($english->json('error.message'), $arabic->json('error.message'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $arabic->json('error.message'));
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $english->json('error.message'));
    }

    #[Test]
    public function a_validation_refusal_is_written_in_arabic_with_the_field_named_in_arabic(): void
    {
        // A member of an account, so the request reaches the validator rather
        // than being refused for acting on no account.
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $response = $this->actingAs($user)
            ->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/me/api-tokens', []);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $response->json('error.message'));

        // A framework rule, with the field named as a customer reads it and
        // never by its wire name.
        $name = implode(' ', (array) $response->json('error.details.fields.name'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $name);
        $this->assertStringNotContainsString('name', $name);

        // And a request's own sentence, from the same catalogue.
        $password = implode(' ', (array) $response->json('error.details.fields.current_password'));
        $this->assertSame(__('validation.requests.api_token.current_password_required', [], 'ar'), $password);
    }

    #[Test]
    public function a_regional_variant_resolves_to_the_language_the_platform_serves(): void
    {
        $user = User::factory()->create();

        foreach (['ar-KW', 'ar-SA,ar;q=0.9,en;q=0.5', 'ar_EG'] as $header) {
            $this->flushHeaders();
            $response = $this->actingAs($user)
                ->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/vps/01J00000000000000000000000');

            $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $response->json('error.message'), $header);
        }

        foreach (['en-US', 'en-GB,en;q=0.8', 'en'] as $header) {
            $this->flushHeaders();
            $response = $this->actingAs($user)
                ->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/vps/01J00000000000000000000000');

            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $response->json('error.message'), $header);
        }
    }

    #[Test]
    public function an_unsupported_or_malformed_language_falls_back_to_the_platform_default_deterministically(): void
    {
        $user = User::factory()->create();

        $baseline = $this->actingAs($user)->getJson('/api/v1/vps/01J00000000000000000000000');

        foreach (['fr', 'ja-JP,ja;q=0.9', '*', 'xx-YY', str_repeat('z', 300), "ar\r\nX-Injected: 1", '', '   '] as $header) {
            $this->flushHeaders();
            $response = $this->actingAs($user)
                ->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/vps/01J00000000000000000000000');

            $this->assertSame($baseline->json('error.message'), $response->json('error.message'), var_export($header, true));
        }
    }

    #[Test]
    public function the_locale_is_applied_before_authentication_so_a_401_speaks_the_customers_language(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'auth.unauthenticated')
            ->assertJsonPath('error.message', __('errors.auth.unauthenticated', [], 'ar'));

        $this->flushHeaders();

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/no-such-route')
            ->assertNotFound()
            ->assertJsonPath('error.message', __('errors.resource.not_found', [], 'ar'));
    }

    #[Test]
    public function the_locale_does_not_leak_from_one_request_into_the_next(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('Accept-Language', 'ar')->getJson('/api/v1/vps/01J00000000000000000000000');
        $this->flushHeaders();

        $next = $this->actingAs($user)->getJson('/api/v1/vps/01J00000000000000000000000');

        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $next->json('error.message'));
    }
}
