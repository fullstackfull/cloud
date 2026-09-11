<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Enums\CustomerCapability;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\EveryPublishedCapabilityIsEnforcedTest;

/**
 * The role explanation, from the same authorization the endpoints enforce.
 *
 * The audit's AS-12 was that the team screen had no permission table at all,
 * so an owner assigned "Billing" to somebody and found out what it meant
 * afterwards. The endpoint tested here is the fix, and what matters about it
 * is not that it returns a matrix — it is that the matrix is *computed from
 * the role model*, so it cannot describe a permission the server does not
 * honour.
 *
 * The architecture gate beside this one
 * ({@see EveryPublishedCapabilityIsEnforcedTest}) proves
 * the published set and the enforced set are equal. These tests prove the
 * endpoint reports the model faithfully, that it is readable by the people who
 * need it, and that the two unenforced permissions stay unpublished.
 */
final class WhatEachRoleCanDoTest extends TeamApiTestCase
{
    #[Test]
    public function every_role_is_published_with_the_whole_capability_matrix(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $response = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/roles');

        $response->assertOk();

        /** @var list<array<string, mixed>> $roles */
        $roles = $response->json('data');

        // All five, so a reader can compare rather than infer.
        self::assertCount(count(CustomerRole::cases()), $roles);

        $ids = array_map(static fn (array $role): string => (string) $role['id'], $roles);
        self::assertSame(
            array_map(static fn (CustomerRole $role): string => $role->value, CustomerRole::cases()),
            $ids,
        );

        foreach ($roles as $role) {
            self::assertCount(count(CustomerCapability::cases()), $role['capabilities']);
        }
    }

    #[Test]
    public function what_the_matrix_says_is_what_the_role_model_says(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $roles = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/roles')
            ->json('data');

        foreach ($roles as $published) {
            $role = CustomerRole::from((string) $published['id']);

            foreach ($published['capabilities'] as $capability) {
                $expected = $role->can((string) $capability['permission']);

                self::assertSame(
                    $expected,
                    $capability['granted'],
                    sprintf(
                        '%s / %s: the matrix says %s and the role model says %s',
                        $role->value,
                        $capability['permission'],
                        $capability['granted'] ? 'yes' : 'no',
                        $expected ? 'yes' : 'no',
                    ),
                );
            }
        }
    }

    #[Test]
    public function the_two_permissions_nothing_enforces_are_not_offered_as_capabilities(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $body = (string) $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/roles')
            ->getContent();

        /*
         * An owner's permission list contains both of these. Publishing them
         * would tell a customer the platform can close their account and
         * manage their payment methods from this screen, and it can do
         * neither.
         */
        self::assertStringNotContainsString('customer.close', $body);
        self::assertStringNotContainsString('billing.methods.manage', $body);
    }

    #[Test]
    public function a_read_only_member_may_read_it_because_they_are_the_ones_asking(): void
    {
        [$customer] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);

        $capabilities = $this->actingAs($member)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/roles')
            ->assertOk()
            ->json('data.4.capabilities');

        // Their own row: they can see and ask, and that is all.
        $granted = array_values(array_map(
            static fn (array $capability): string => (string) $capability['id'],
            array_filter($capabilities, static fn (array $capability): bool => $capability['granted'] === true),
        ));

        sort($granted);

        self::assertSame(['ask_support', 'view_services'], $granted);
    }

    #[Test]
    public function owner_is_published_as_the_one_role_nobody_can_be_assigned(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $roles = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/roles')
            ->json('data');

        $assignable = array_values(array_map(
            static fn (array $role): string => (string) $role['id'],
            array_filter($roles, static fn (array $role): bool => $role['assignable'] === true),
        ));

        self::assertSame(CustomerRole::assignableValues(), $assignable);
        self::assertSame([true], array_values(array_unique(array_map(
            static fn (array $role): bool => (bool) $role['is_owner'],
            array_filter($roles, static fn (array $role): bool => $role['id'] === 'owner'),
        ))));
    }

    #[Test]
    public function the_matrix_costs_no_query(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/roles')
            ->assertOk();

        /*
         * The session's user and the acting membership, and nothing else. The
         * matrix is the enum; a screen that asked the database what a role
         * means would be inventing a second answer to a question the code
         * already answers.
         */
        self::assertLessThan(5, $queries, 'The role matrix is reading rows it does not need.');
    }
}
