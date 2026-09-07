<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cross-tenant reachability for a caller who is a member of both accounts.
 *
 * Every other cross-tenant test here uses a stranger — a login with no
 * relationship at all to the account whose id it is quoting — and a stranger is
 * refused by almost any implementation, including the wrong ones. The case that
 * separates scoping from checking is the consultant who really is a member of
 * two accounts: `$user->roleWithin($invoice->customer_id)` says yes, a policy
 * that asks "may this user touch this row?" says yes, and the payment is
 * collected from the wrong balance. Only resolving the id *within* the account
 * the middleware picked says no.
 *
 * Both endpoints that take an id are covered, and the account being acted for is
 * named the only way the platform accepts — the X-Lynomia-Customer header, which
 * is checked against accepted memberships — never a customer id in a body or
 * query string.
 */
final class PaymentsAreScopedToTheActingAccountTest extends PaymentsApiTestCase
{
    #[Test]
    public function a_member_of_both_accounts_cannot_pay_the_other_accounts_invoice(): void
    {
        [$acting, $user] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        // The same login, genuinely an owner of both.
        $this->memberOf($other, user: $user);

        $theirInvoice = $this->openInvoice($other);

        $refused = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->postJson("/api/v1/invoices/{$theirInvoice->id}/payments")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $invented = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->postJson('/api/v1/invoices/01JZZZZZZZZZZZZZZZZZZZZZZZ/payments')
            ->assertNotFound();

        // Identical, or the response sorts real ids from invented ones for a
        // caller who is entitled to neither of them here.
        $this->assertSame($invented->json('error.code'), $refused->json('error.code'));
        $this->assertSame($invented->json('error.message'), $refused->json('error.message'));

        // Nothing was opened against the other account, and nothing was
        // collected under the acting one either.
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, Transaction::query()->count());

        // And the invoice is payable — it is the account it was quoted under
        // that was wrong, not the document.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $other->id)
            ->postJson("/api/v1/invoices/{$theirInvoice->id}/payments")
            ->assertCreated();
    }

    #[Test]
    public function a_member_of_both_accounts_cannot_read_the_other_accounts_payment(): void
    {
        [$acting, $user] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $this->memberOf($other, user: $user);

        $theirs = Transaction::factory()->forCustomer($other)->create();

        $response = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->getJson("/api/v1/payments/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString((string) $theirs->provider_reference, $body);
    }

    #[Test]
    public function the_history_shows_only_the_account_being_acted_for(): void
    {
        [$acting, $user] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $this->memberOf($other, user: $user);

        $ours = Transaction::factory()->forCustomer($acting)->create();
        $theirs = Transaction::factory()->forCustomer($other)->create();

        $response = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->getJson('/api/v1/payments')
            ->assertOk();

        // Not "contains ours" — exactly ours. A ledger that quietly unions the
        // caller's accounts is a reconciliation nobody can explain.
        $this->assertSame([$ours->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($theirs->id, $body);
        $this->assertStringNotContainsString($other->id, $body);
    }
}
