<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use Tests\TestCase;

/**
 * Shared scaffolding for the shared-hosting endpoints.
 *
 * Two customers with two logins exist in almost every test here on purpose:
 * the interesting question about a hosting API is not whether it can show you
 * your own account, it is whether it can be talked into opening a control
 * panel session for somebody else's.
 */
abstract class HostingApiTestCase extends TestCase
{
    use RefreshDatabase;

    private ?HostingNode $node = null;

    private ?HostingPackage $package = null;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * One factory for the whole request lifecycle of a test, so that an
         * account registered with the fake panel during arrangement is still
         * there when the controller resolves the factory. The container does
         * not bind it as a singleton in production because the real adapters
         * hold no per-node state; the fake does, by design.
         */
        $this->app->singleton(HostingProviderFactory::class);
    }

    /**
     * A customer account with an accepted membership, and the user who holds it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, $role)];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing, so every
            // membership these tests rely on is explicitly accepted.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * One hosting account belonging to a customer, on the shared fake node.
     */
    protected function hostingAccountFor(
        Customer $customer,
        HostingAccountStatus $status = HostingAccountStatus::Active,
        ?string $username = null,
        bool $atPanel = true,
    ): HostingAccount {
        $account = HostingAccount::factory()
            ->when($username !== null, fn ($factory) => $factory->named($username))
            ->create([
                'hosting_node_id' => $this->node()->id,
                'hosting_package_id' => $this->package()->id,
                'customer_id' => $customer->id,
                'status' => $status,
            ]);

        if ($atPanel) {
            $this->registerAtPanel($account);
        }

        return $account->fresh() ?? $account;
    }

    /**
     * Make the fake panel believe the account exists on it.
     *
     * The fake refuses every operation against an account it never created,
     * exactly as a real node does, so an SSO test that skipped this would be
     * testing the "no such account" path without meaning to.
     */
    protected function registerAtPanel(HostingAccount $account): void
    {
        $this->panel()->createAccount($this->node(), new CreateAccountRequest(
            username: $account->username,
            primaryDomain: $account->primary_domain,
            password: 'not-stored-anywhere',
            packageName: $this->package()->panel_package_name,
            contactEmail: 'owner@example.test',
        ));
    }

    protected function panel(): FakeHostingProvider
    {
        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node());

        return $panel;
    }

    /**
     * One node, reused across a test so that every account lands somewhere
     * real without each helper building a fleet.
     */
    protected function node(): HostingNode
    {
        return $this->node ??= HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'hostname' => 'shared-node-under-test.lynomia.test',
        ]);
    }

    protected function package(): HostingPackage
    {
        return $this->package ??= HostingPackage::factory()->create([
            'slug' => 'hosting-starter',
            'panel_package_name' => 'lyn_starter_internal',
            'disk_quota_mib' => 10_240,
            'bandwidth_quota_mib' => 512_000,
        ]);
    }
}
