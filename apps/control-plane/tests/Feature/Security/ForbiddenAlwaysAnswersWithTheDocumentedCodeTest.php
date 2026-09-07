<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\AccountPermissionRequiredException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One refusal, one code.
 *
 * The API documents `auth.forbidden` for "you may not do this", and a client
 * branches on the code rather than on the status or the prose. Three different
 * things can produce a 403 here — a domain exception, `abort(403)`, and a Gate
 * denial — and before this test they produced two different codes, because
 * Laravel converts an AuthorizationException into an AccessDeniedHttpException
 * before the renderer runs and the renderer's AuthorizationException arm was
 * therefore unreachable.
 *
 * That is not a cosmetic inconsistency. Nine modules independently invented
 * their own DomainException to work around it rather than use the framework's,
 * which is what a wrong default looks like from the inside: everybody routes
 * around it and nobody reports it.
 */
final class ForbiddenAlwaysAnswersWithTheDocumentedCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_domain_permission_failure_answers_auth_forbidden(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->get(
            '/api/test/forbidden-domain',
            static fn () => throw AccountPermissionRequiredException::forPermission('order.manage'),
        );

        $this->actingAs(User::factory()->create())
            ->getJson('/api/test/forbidden-domain')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden')
            ->assertJsonPath('error.details.required_permission', 'order.manage');
    }

    #[Test]
    public function a_framework_authorization_failure_answers_the_same_code(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->get(
            '/api/test/forbidden-gate',
            static fn () => throw new AuthorizationException,
        );

        $this->actingAs(User::factory()->create())
            ->getJson('/api/test/forbidden-gate')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function a_bare_abort_answers_the_same_code(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->get(
            '/api/test/forbidden-abort',
            static fn () => abort(403),
        );

        $this->actingAs(User::factory()->create())
            ->getJson('/api/test/forbidden-abort')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function no_refusal_discloses_what_it_was_protecting(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->get(
            '/api/test/forbidden-quiet',
            static fn () => throw AccountPermissionRequiredException::forPermission('vm.manage'),
        );

        $body = $this->actingAs(User::factory()->create())
            ->getJson('/api/test/forbidden-quiet')
            ->assertStatus(403)
            ->getContent();

        // The permission is named on purpose - an integrator needs to know which
        // grant they lack. Nothing about the resource is, because this is thrown
        // before any lookup and a 403 that only appears for ids that exist is an
        // enumeration oracle wearing a different status code.
        $this->assertStringContainsString('vm.manage', (string) $body);
        $this->assertStringNotContainsString('customer_id', (string) $body);
        $this->assertStringNotContainsString('service_id', (string) $body);
    }
}
