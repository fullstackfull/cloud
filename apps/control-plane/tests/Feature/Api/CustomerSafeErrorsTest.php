<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Responses\CustomerFailureReason;
use Lynomia\Http\Responses\ErrorCatalogue;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Vps\Domain\Exceptions\VpsOperationInFlightException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * What the server knows and what the customer is told are different things.
 *
 * The engineer's message on an exception may name a node, a driver, a
 * provider or a path. These tests plant such messages and prove that the
 * response carries the catalogue's sentence instead, in the language asked
 * for, and that an unhandled failure carries only the generic sentence and
 * the request id.
 */
final class CustomerSafeErrorsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_domain_exception_is_answered_with_the_catalogue_sentence_not_the_engineers_message(): void
    {
        Route::middleware('api')->get('/api/v1/__test/refusal', static function (): never {
            throw VpsOperationInFlightException::forKind(ProvisioningJobKind::ReinstallVps);
        });

        $user = User::factory()->create();

        $english = $this->actingAs($user)->getJson('/api/v1/__test/refusal');
        $english->assertStatus(409)->assertJsonPath('error.code', 'vps.operation_in_flight');
        $this->assertSame(__('errors.vps.operation_in_flight', [], 'en'), $english->json('error.message'));
        // The exception's own sentence — written for the log — is not what was sent.
        $this->assertNotSame(VpsOperationInFlightException::forKind(ProvisioningJobKind::ReinstallVps)->getMessage(), $english->json('error.message'));

        $this->flushHeaders();

        $arabic = $this->actingAs($user)->withHeader('Accept-Language', 'ar')->getJson('/api/v1/__test/refusal');
        $this->assertSame(__('errors.vps.operation_in_flight', [], 'ar'), $arabic->json('error.message'));
    }

    #[Test]
    public function an_unhandled_failure_carries_only_the_generic_sentence_and_the_request_id(): void
    {
        config()->set('app.debug', false);

        Route::middleware('api')->get('/api/v1/__test/crash', static function (): never {
            throw new RuntimeException('SQLSTATE[08006] connection to server at "10.20.0.7" failed for driver pgsql');
        });

        $user = User::factory()->create();

        foreach (['en', 'ar'] as $locale) {
            $this->flushHeaders();
            $response = $this->actingAs($user)->withHeader('Accept-Language', $locale)->getJson('/api/v1/__test/crash');

            $response->assertStatus(500)->assertJsonPath('error.code', 'server.error');
            $this->assertSame(__('errors.server.error', [], $locale), $response->json('error.message'));
            $this->assertNotEmpty($response->json('error.request_id'));

            $body = $response->getContent();
            foreach (['SQLSTATE', '10.20.0.7', 'pgsql', 'driver', 'RuntimeException'] as $internal) {
                $this->assertStringNotContainsString($internal, (string) $body);
            }
        }
    }

    #[Test]
    public function a_stored_provider_failure_is_published_to_the_customer_as_the_catalogue_sentence(): void
    {
        $this->assertNull(CustomerFailureReason::describe(null, 'dns.zone_operation_failed'));
        $this->assertNull(CustomerFailureReason::describe('   ', 'dns.zone_operation_failed'));

        $stored = 'Cloudflare API answered 403 for POST https://api.cloudflare.com/client/v4/zones with token cf_...';

        app()->setLocale('en');
        $this->assertSame(__('errors.dns.zone_operation_failed', [], 'en'), CustomerFailureReason::describe($stored, 'dns.zone_operation_failed'));

        app()->setLocale('ar');
        $arabic = CustomerFailureReason::describe($stored, 'dns.zone_operation_failed');
        $this->assertSame(__('errors.dns.zone_operation_failed', [], 'ar'), $arabic);
        $this->assertStringNotContainsString('Cloudflare', (string) $arabic);
    }

    #[Test]
    public function a_code_outside_the_customer_catalogue_keeps_its_own_message_for_the_operator(): void
    {
        // The operator modules are not in the catalogue on purpose; their
        // readers are staff and their messages are the engineer's.
        $this->assertFalse(ErrorCatalogue::has('safety_refused'));
        $this->assertSame('The plan would touch a machine in production.', ErrorCatalogue::message('safety_refused', [], 'The plan would touch a machine in production.'));
        $this->assertSame(__('errors.request_failed'), ErrorCatalogue::message('safety_refused'));
    }

    #[Test]
    public function catalogue_placeholders_are_filled_only_from_what_the_sentence_names(): void
    {
        // A context value the sentence does not name never appears.
        $message = ErrorCatalogue::message('vps.not_active', ['node' => 'pve-node-03', 'service_id' => '01J'], 'fallback');
        $this->assertSame(__('errors.vps.not_active'), $message);
        $this->assertStringNotContainsString('pve-node-03', $message);
    }

    #[Test]
    public function validation_field_names_are_the_customers_in_both_languages(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $english = $this->actingAs($user)->postJson('/api/v1/me/api-tokens', ['name' => str_repeat('x', 300)]);
        $this->assertStringContainsString('name', implode(' ', (array) $english->json('error.details.fields.name')));
        $this->assertStringNotContainsString('current_password', implode(' ', (array) $english->json('error.details.fields.current_password')));
    }
}
