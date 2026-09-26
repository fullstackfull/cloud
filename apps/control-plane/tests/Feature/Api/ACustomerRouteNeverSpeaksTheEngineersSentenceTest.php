<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Responses\ErrorCatalogue;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\RetryRefusedException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * On a customer route, a code with no catalogue entry is answered with the
 * catalogue's generic sentence — never with the exception's own.
 *
 * ===========================================================================
 * WHAT WAS OPEN (re-audit after round two, band A, "could not establish")
 * ===========================================================================
 *
 * The renderer answers a DomainException with `ErrorCatalogue::message(code,
 * context, $e->getMessage())`, and the catalogue falls back to that third
 * argument — the engineer's sentence, which may name a node, a job or a
 * provider — for any code it has no entry for. Twenty-four codes (twenty-six
 * at this base: drift.*, infrastructure.*, monitoring.invalid_metric,
 * provisioning.adoption_*, repoint_*, retry_*, build_may_exist, rbac.*) have
 * none. Whether one of them could reach a customer route was not established.
 *
 * Answered now, in two ways of different strength:
 *
 *  - by route analysis, for the codes it can read:
 *    `NoCustomerRouteReachesAnUncataloguedCodeTest` walks from the classes the
 *    customer route files name to the classes that declare an uncatalogued
 *    code in one of five literal spellings. It reaches none of the 24/26
 *    above; it does reach HandlerNotRegisteredException and
 *    ProvisioningFailedException, only through the queued RunProvisioningJob
 *    that catches them, and excuses those on checks it re-runs. A code spelled
 *    any other way is not read;
 *  - by construction, here: on `api/v1/*` the renderer no longer offers the
 *    exception's sentence as the fallback at all, so a code that one day does
 *    reach a customer is answered with `errors.request_failed`, translated,
 *    and discloses nothing. The operator surface keeps the engineer's
 *    sentence, which is what its readers need.
 */
final class ACustomerRouteNeverSpeaksTheEngineersSentenceTest extends TestCase
{
    use RefreshDatabase;

    private const string ENGINEERS_SENTENCE = 'This job has not stopped, so it cannot be run again.';

    #[Test]
    public function an_uncatalogued_refusal_on_a_customer_route_is_answered_with_the_generic_sentence_in_both_languages(): void
    {
        $this->assertFalse(ErrorCatalogue::has('provisioning.retry_not_settled'), 'The probe code has been catalogued; pick another uncatalogued one.');

        Route::middleware('api')->get('/api/v1/__test/uncatalogued', static function (): never {
            throw RetryRefusedException::becauseJobIsNotSettled('01JOBNODEPVE3', ProvisioningJobStatus::Running);
        });

        $user = User::factory()->create();

        foreach (['en', 'ar'] as $locale) {
            $this->flushHeaders();

            $response = $this->actingAs($user)->withHeader('Accept-Language', $locale)->getJson('/api/v1/__test/uncatalogued');

            $response->assertStatus(409)->assertJsonPath('error.code', 'provisioning.retry_not_settled');
            $this->assertSame(__('errors.request_failed', [], $locale), $response->json('error.message'));
            $this->assertStringNotContainsString(self::ENGINEERS_SENTENCE, (string) $response->getContent());
            $this->assertStringNotContainsString('01JOBNODEPVE3', (string) $response->getContent());
        }
    }

    #[Test]
    public function an_uncatalogued_http_failure_on_a_customer_route_carries_no_message_of_its_own(): void
    {
        Route::middleware('api')->get('/api/v1/__test/gone', static function (): never {
            abort(410, 'Machine 12345 on pve-node-03 was destroyed by the reconciler.');
        });

        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/__test/gone');

        $response->assertStatus(410)->assertJsonPath('error.code', 'http.410');
        $this->assertSame(__('errors.request_failed', [], 'en'), $response->json('error.message'));
        $this->assertStringNotContainsString('pve-node-03', (string) $response->getContent());
    }

    #[Test]
    public function the_operator_surface_keeps_the_engineers_sentence(): void
    {
        Route::middleware('api')->get('/api/admin/__test/uncatalogued', static function (): never {
            throw RetryRefusedException::becauseJobIsNotSettled('01JOBNODEPVE3', ProvisioningJobStatus::Running);
        });

        $response = $this->actingAs(User::factory()->create())->getJson('/api/admin/__test/uncatalogued');

        $response->assertStatus(409)->assertJsonPath('error.code', 'provisioning.retry_not_settled');
        $this->assertSame(self::ENGINEERS_SENTENCE, $response->json('error.message'));
    }
}
