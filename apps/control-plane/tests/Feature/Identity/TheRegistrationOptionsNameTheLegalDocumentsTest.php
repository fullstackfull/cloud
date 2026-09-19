<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Identity\Domain\Enums\LegalDocumentType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\LegalAcceptance;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The documents a customer accepts, and what happens when they do not exist.
 *
 * ===========================================================================
 * WHAT WAS WRONG
 * ===========================================================================
 *
 * The registration screen asked a customer to accept a terms of service and
 * an acceptable use policy. The API required the box to be ticked.
 * `config/legal.php` said the acceptance "is recorded against the account".
 *
 * None of the last part was true. `accepts_terms` appeared in exactly one
 * place in the whole repository — a validation rule — and was discarded the
 * moment it passed. Nothing was stored, so nothing could be produced later.
 * And because the URLs were optional, an account could be created on the
 * strength of a customer ticking a box beside two document names that linked
 * nowhere, because the documents did not exist.
 *
 * Two changes, both asserted here:
 *
 *  - **Registration fails closed.** No published documents, no accounts. An
 *    unwritten policy is not a lenient policy, and a launch prerequisite that
 *    quietly lets customers in is not a prerequisite;
 *  - **An acceptance names a revision.** A URL alone cannot be accepted
 *    meaningfully, so a version is required beside it and stored with every
 *    acceptance. "This customer accepted the terms" is not a fact anyone can
 *    act on once the terms have been rewritten twice.
 *
 * What has not changed is that this repository does not write legal text.
 * Publishing is still an operator setting four values, and unset is still the
 * honest default — it now refuses registration rather than allowing it.
 *
 * ===========================================================================
 * WHY A SCHEME AND A SHAPE ARE CHECKED
 * ===========================================================================
 *
 * The values are an operator's, so this is not a trust boundary in the usual
 * sense. But the options endpoint is unauthenticated and the portal renders
 * the URL as an anchor to every visitor, so a `javascript:` left in an
 * environment file would turn one configuration mistake into a script in
 * every browser that opens the registration page. And a version ends up in a
 * database column that is supposed to be a stable identifier, so a sentence
 * or a control character in one is refused rather than stored.
 *
 * Either way the value reads as "not published", which is a state the screen
 * and the registration endpoint both already handle.
 */
final class TheRegistrationOptionsNameTheLegalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function the_shipped_configuration_publishes_nothing(): void
    {
        /*
         * Read as source, not evaluated.
         *
         * `require`-ing the file would run `env()` against this suite's own
         * environment — which publishes two documents so the rest of the
         * tests can register anybody — and the claim here is about a fresh
         * deployment: an operator who has not published the documents must
         * not find a URL or a version somebody chose for them. So what is
         * asserted is that each key reads its environment variable with no
         * fallback argument at all.
         */
        $source = (string) file_get_contents(config_path('legal.php'));

        foreach (['LEGAL_TERMS_URL', 'LEGAL_TERMS_VERSION', 'LEGAL_AUP_URL', 'LEGAL_AUP_VERSION'] as $variable) {
            $this->assertStringContainsString(sprintf("env('%s')", $variable), $source);
            $this->assertStringNotContainsString(sprintf("env('%s',", $variable), $source);
        }
    }

    #[Test]
    public function with_nothing_published_the_form_is_told_so_and_offered_no_documents(): void
    {
        $this->publishNothing();

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', false)
            ->assertJsonPath('data.legal.documents', []);
    }

    #[Test]
    public function with_both_published_the_form_is_offered_each_document_and_its_revision(): void
    {
        $this->publishBoth();

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', true)
            ->assertJsonPath('data.legal.documents.0.type', 'terms')
            ->assertJsonPath('data.legal.documents.0.url', 'https://lynomia.example/legal/terms')
            ->assertJsonPath('data.legal.documents.0.version', '2026-04-01')
            ->assertJsonPath('data.legal.documents.1.type', 'aup')
            ->assertJsonPath('data.legal.documents.1.url', 'https://lynomia.example/legal/acceptable-use')
            ->assertJsonPath('data.legal.documents.1.version', '1.2');
    }

    #[Test]
    public function a_url_with_no_revision_is_not_a_published_document(): void
    {
        // Half-published is the state this type exists to make impossible: a
        // page a customer can read and an acceptance nobody can pin to a
        // revision of it.
        $this->publishBoth();
        config()->set('legal.terms_version', null);

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', false)
            ->assertJsonCount(1, 'data.legal.documents')
            ->assertJsonPath('data.legal.documents.0.type', 'aup');
    }

    #[Test]
    public function a_revision_with_no_url_is_not_a_published_document(): void
    {
        // The other half: a revision nobody can read.
        $this->publishBoth();
        config()->set('legal.aup_url', null);

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', false)
            ->assertJsonCount(1, 'data.legal.documents')
            ->assertJsonPath('data.legal.documents.0.type', 'terms');
    }

    #[Test]
    public function one_document_published_before_the_other_is_still_not_enough_to_register(): void
    {
        /*
         * Two documents, two reviews, two publication dates — so the screen
         * shows whichever exists, and this used to be the whole story. It is
         * not enough to enrol anyone: a customer cannot half-accept the terms
         * on which they hold an account, so both are required.
         */
        $this->publishNothing();
        config()->set('legal.aup_url', 'https://lynomia.example/legal/acceptable-use');
        config()->set('legal.aup_version', '1.2');

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', false)
            ->assertJsonCount(1, 'data.legal.documents');

        $this->postJson(route('api.v1.register'), $this->validPayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'registration.unavailable');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valuesThatAreNotAPublishedUrl(): iterable
    {
        yield 'a script scheme' => ['javascript:alert(1)'];
        yield 'an inline document' => ['data:text/html,<script>alert(1)</script>'];
        yield 'a file on the controller' => ['file:///etc/passwd'];
        yield 'a path with no origin' => ['/legal/terms'];
        yield 'a bare hostname' => ['lynomia.example/legal/terms'];
        yield 'an empty string' => [''];
        yield 'whitespace' => ['   '];
        yield 'a number' => [42];
        yield 'a list' => [['https://lynomia.example/legal/terms']];
        yield 'a boolean' => [true];
    }

    #[Test]
    #[DataProvider('valuesThatAreNotAPublishedUrl')]
    public function a_url_the_browser_should_not_be_handed_reads_as_unpublished(mixed $configured): void
    {
        $this->publishBoth();
        config()->set('legal.terms_url', $configured);

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', false)
            ->assertJsonCount(1, 'data.legal.documents')
            ->assertJsonPath('data.legal.documents.0.type', 'aup');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valuesThatAreNotAPublishedVersion(): iterable
    {
        yield 'an empty string' => [''];
        yield 'whitespace' => ['   '];
        yield 'a sentence' => ['the one we agreed in the meeting on Tuesday'];
        yield 'a newline' => ["2026-04-01\nor thereabouts"];
        // Interior, deliberately: a trailing NUL is padding and trim() strips
        // it like any other, leaving a perfectly good identifier behind.
        yield 'an interior control character' => ["2026-\x0004-01"];
        yield 'a leading separator' => ['-2026-04-01'];
        yield 'something far too long' => [str_repeat('9', 65)];
        yield 'a number' => [20260401];
        yield 'a list' => [['2026-04-01']];
        yield 'a boolean' => [true];
    }

    #[Test]
    #[DataProvider('valuesThatAreNotAPublishedVersion')]
    public function a_revision_that_is_not_a_stable_identifier_reads_as_unpublished(mixed $configured): void
    {
        $this->publishBoth();
        config()->set('legal.terms_version', $configured);

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.registration_permitted', false);
    }

    #[Test]
    public function registration_with_nothing_published_is_refused_and_leaves_nothing_behind(): void
    {
        Notification::fake();
        $this->publishNothing();

        $this->postJson(route('api.v1.register'), $this->validPayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'registration.unavailable');

        /*
         * Zero side effects, and each of these is a different way the refusal
         * could have been half-hearted: an account, a billing account, an
         * acceptance recorded against nothing — or mail, which would tell a
         * stranger that this address is now involved with a platform that has
         * not enrolled them.
         */
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, LegalAcceptance::query()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_refusal_names_no_document_no_configuration_key_and_no_version(): void
    {
        $this->publishNothing();

        $body = $this->postJson(route('api.v1.register'), $this->validPayload())
            ->assertStatus(503)
            ->getContent();

        /*
         * An unauthenticated endpoint that listed which of the platform's own
         * launch prerequisites were outstanding would be a reliable readout of
         * deployment state for anybody who asked.
         */
        foreach (['terms', 'aup', 'legal.', 'LEGAL_', 'config', 'version'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $body);
        }
    }

    #[Test]
    public function a_successful_registration_records_one_acceptance_per_document(): void
    {
        $this->publishBoth();

        $this->postJson(route('api.v1.register'), $this->validPayload())->assertAccepted();

        $user = User::query()->sole();
        $acceptances = LegalAcceptance::query()->where('user_id', $user->id)->orderBy('document_type')->get();

        $this->assertCount(2, $acceptances);

        $this->assertSame(LegalDocumentType::AcceptableUse, $acceptances[0]->document_type);
        $this->assertSame('1.2', $acceptances[0]->document_version);
        $this->assertSame('https://lynomia.example/legal/acceptable-use', $acceptances[0]->document_url);

        $this->assertSame(LegalDocumentType::Terms, $acceptances[1]->document_type);
        $this->assertSame('2026-04-01', $acceptances[1]->document_version);
        $this->assertSame('https://lynomia.example/legal/terms', $acceptances[1]->document_url);

        $this->assertNotNull($acceptances[0]->accepted_at);
    }

    #[Test]
    public function the_revision_recorded_is_the_servers_and_never_the_browsers(): void
    {
        $this->publishBoth();

        /*
         * A client that could name the revision it was agreeing to could name
         * one from two years ago, or one that never existed. The submitted
         * values are not even a field — they are ignored on the way in — and
         * what is stored is read from configuration on the server.
         */
        $this->postJson(route('api.v1.register'), $this->validPayload([
            'terms_version' => '1066',
            'aup_version' => '1066',
            'document_version' => '1066',
        ]))->assertAccepted();

        $versions = LegalAcceptance::query()->pluck('document_version')->sort()->values()->all();

        $this->assertSame(['1.2', '2026-04-01'], $versions);
    }

    #[Test]
    public function a_registration_that_creates_no_account_records_no_acceptance(): void
    {
        Notification::fake();
        $this->publishBoth();

        // The address already has an account, so this produces nothing — and
        // an acceptance written anyway would be an acceptance belonging to
        // somebody else's registration attempt.
        User::factory()->create(['email' => 'amal@example.com']);

        $this->postJson(route('api.v1.register'), $this->validPayload())->assertAccepted();

        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    #[Test]
    public function an_acceptance_cannot_be_rewritten_or_removed(): void
    {
        $this->publishBoth();
        $this->postJson(route('api.v1.register'), $this->validPayload())->assertAccepted();

        $acceptance = LegalAcceptance::query()->where('document_type', LegalDocumentType::Terms)->sole();

        // Evidence that can be edited afterwards is not evidence.
        try {
            $acceptance->update(['document_version' => 'something-else']);
            $this->fail('A legal acceptance was updated.');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('cannot be updated', $refused->getMessage());
        }

        try {
            $acceptance->delete();
            $this->fail('A legal acceptance was deleted.');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('cannot be deleted', $refused->getMessage());
        }

        $this->assertSame('2026-04-01', $acceptance->fresh()->document_version);
    }

    #[Test]
    public function a_later_revision_is_a_new_row_and_leaves_the_earlier_one_alone(): void
    {
        $this->publishBoth();
        $this->postJson(route('api.v1.register'), $this->validPayload())->assertAccepted();

        $user = User::query()->sole();

        /*
         * The terms are rewritten and this person accepts the new text. The
         * platform must still be able to say what they agreed to before, so
         * the history appends rather than moves.
         */
        LegalAcceptance::create([
            'user_id' => $user->id,
            'document_type' => LegalDocumentType::Terms,
            'document_version' => '2026-10-01',
            'document_url' => 'https://lynomia.example/legal/terms',
            'accepted_at' => now(),
        ]);

        $versions = LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->where('document_type', LegalDocumentType::Terms)
            ->orderBy('document_version')
            ->pluck('document_version')
            ->all();

        $this->assertSame(['2026-04-01', '2026-10-01'], $versions);
    }

    #[Test]
    public function the_endpoint_still_answers_without_a_session(): void
    {
        /*
         * The whole point of putting this here: it is read before an account
         * exists. A legal document behind authentication would be a document
         * a customer cannot read until after they have accepted it.
         */
        $this->publishBoth();

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['legal' => ['registration_permitted', 'documents']]]);
    }

    #[Test]
    public function the_options_endpoint_never_says_where_the_values_come_from(): void
    {
        $this->publishNothing();

        $body = (string) $this->getJson('/api/v1/registration/options')->assertOk()->getContent();

        foreach (['legal.terms_url', 'legal.aup_url', 'LEGAL_TERMS_URL', 'LEGAL_AUP_URL'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    private function publishNothing(): void
    {
        config()->set('legal.terms_url', null);
        config()->set('legal.terms_version', null);
        config()->set('legal.aup_url', null);
        config()->set('legal.aup_version', null);
    }

    private function publishBoth(): void
    {
        config()->set('legal.terms_url', 'https://lynomia.example/legal/terms');
        config()->set('legal.terms_version', '2026-04-01');
        config()->set('legal.aup_url', 'https://lynomia.example/legal/acceptable-use');
        config()->set('legal.aup_version', '1.2');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amal',
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'country' => 'KW',
            'accepts_terms' => true,
        ], $overrides);
    }
}
