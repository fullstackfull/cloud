<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Contracts\TransactionalEmailProvider;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Channels\EmailChannel;
use Lynomia\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Lynomia\Modules\Notifications\Infrastructure\Providers\LaravelMailTransport;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The transactional email seat: catalogued, adapted, and never proven.
 *
 * The `smtp` driver is the platform's own mail transport wearing a
 * catalogue entry, so the readiness engine can say "an email provider is
 * registered and nobody has proven it" instead of "there is no email
 * provider". It is untestable by design, a row for it stays blocked, and
 * the mail the platform sends goes through the same contract the driver
 * implements — so the seat is not a label on a thing that sends mail some
 * other way.
 */
final class TheSmtpDriverIsCataloguedButNeverProvenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_catalogue_offers_smtp_as_untestable_and_a_row_for_it_is_blocked_and_cannot_be_tested(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $catalogue = $this->actingAs($operator)->getJson('/api/admin/providers/catalogue')->assertOk();
        $smtp = collect($catalogue->json('data'))->firstWhere('driver', 'smtp');

        $this->assertNotNull($smtp);
        $this->assertSame('email', $smtp['category']);
        $this->assertFalse($smtp['testable']);
        $this->assertTrue($smtp['available_here']);
        // The relay is MAIL_HOST on the deployment, not an address dialled from here.
        $this->assertFalse($smtp['needs_endpoint']);

        $registered = $this->actingAs($operator)
            ->postJson('/api/admin/providers', [
                'name' => 'deployment-relay',
                'driver' => 'smtp',
                'category' => ProviderCategory::Email->value,
                'environment' => DeploymentEnvironment::Staging->value,
            ])
            ->assertCreated();

        $id = (string) $registered->json('data.id');
        $this->assertSame('not_ready', $registered->json('data.readiness.state'));
        $this->assertSame('blocked_credentials', $registered->json('data.readiness.blocker'));
        $this->assertFalse($registered->json('data.can_test'));

        // No tester exists, and the API says so rather than pretending to connect.
        $this->actingAs($operator)
            ->postJson('/api/admin/providers/'.$id.'/connection-test')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_driver');
    }

    #[Test]
    public function the_mail_the_platform_sends_goes_through_the_same_contract_the_driver_implements(): void
    {
        Mail::fake();

        $this->assertInstanceOf(LaravelMailTransport::class, app(TransactionalEmailProvider::class));
        $this->assertSame('smtp', app(TransactionalEmailProvider::class)->name());

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW', 'billing_email' => 'owner@example.test']);

        $notification = app(NotifyCustomer::class)->execute(
            customerId: (string) $customer->getKey(),
            type: NotificationType::InvoiceIssued,
            idempotencyKey: 'smtp-seat-test',
            data: ['number' => 'INV-1', 'amount' => '1.000 KWD', 'due_date' => '2026-10-01'],
        );

        $this->assertNotNull($notification);

        $delivered = app(EmailChannel::class)->deliver($notification);

        $this->assertSame('owner@example.test', $delivered);
        Mail::assertSent(NotificationMail::class);
    }
}
