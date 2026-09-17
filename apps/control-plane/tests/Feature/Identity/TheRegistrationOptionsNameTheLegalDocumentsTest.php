<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where the documents a customer accepts at registration are published.
 *
 * ===========================================================================
 * THE SPLIT THIS ENFORCES
 * ===========================================================================
 *
 * The registration screen asks a customer to accept a terms of service and an
 * acceptable use policy. It offered no way to read either, and the reason is
 * real: the documents are written and reviewed by people, and this repository
 * does not write legal terms. That stays a launch prerequisite.
 *
 * What was *also* missing was the software half. Publishing the documents has
 * to be an operator setting two variables, not somebody editing a component
 * and deploying the portal — so the URLs travel with the rest of what a
 * registration form is allowed to offer, exactly like the country and
 * currency lists beside them, and the screen renders whichever exists.
 *
 * Unset is the honest default and stays the default. A URL pointing at a page
 * nobody has written would be worse than no URL, because it looks like the
 * prerequisite is met.
 *
 * ===========================================================================
 * AND WHY A SCHEME IS CHECKED
 * ===========================================================================
 *
 * The value is an operator's, so this is not a trust boundary in the usual
 * sense. But this endpoint is unauthenticated and the portal renders the
 * result as an anchor to every visitor, so a `javascript:` left in an
 * environment file would turn one configuration mistake into a script in
 * every browser that opens the registration page. Refusing the scheme costs
 * one function; the value then reads as "not published", which is a state the
 * screen already handles.
 */
final class TheRegistrationOptionsNameTheLegalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nothing_is_published_by_default(): void
    {
        /*
         * Asserted against the shipped configuration rather than against an
         * override, because the claim is about what a fresh deployment does:
         * an operator who has not published the documents must not find a URL
         * somebody chose for them.
         */
        $this->assertNull(config('legal.terms_url'));
        $this->assertNull(config('legal.aup_url'));

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.terms_url', null)
            ->assertJsonPath('data.legal.aup_url', null);
    }

    #[Test]
    public function a_published_document_is_offered_to_the_form(): void
    {
        config()->set('legal.terms_url', 'https://lynomia.example/legal/terms');
        config()->set('legal.aup_url', 'https://lynomia.example/legal/acceptable-use');

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.terms_url', 'https://lynomia.example/legal/terms')
            ->assertJsonPath('data.legal.aup_url', 'https://lynomia.example/legal/acceptable-use');
    }

    #[Test]
    public function one_document_may_be_published_before_the_other(): void
    {
        // Two documents, two reviews, two publication dates. A screen that
        // waited for both would show neither.
        config()->set('legal.aup_url', 'https://lynomia.example/legal/acceptable-use');

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.terms_url', null)
            ->assertJsonPath('data.legal.aup_url', 'https://lynomia.example/legal/acceptable-use');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valuesThatAreNotAPublishedDocument(): iterable
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
    #[DataProvider('valuesThatAreNotAPublishedDocument')]
    public function a_value_the_browser_should_not_be_handed_reads_as_unpublished(mixed $configured): void
    {
        config()->set('legal.terms_url', $configured);

        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonPath('data.legal.terms_url', null);
    }

    #[Test]
    public function the_endpoint_still_answers_without_a_session(): void
    {
        /*
         * The whole point of putting the URLs here: this is read before an
         * account exists. A legal document behind authentication would be a
         * document a customer cannot read until after they have accepted it.
         */
        $this->getJson('/api/v1/registration/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['legal' => ['terms_url', 'aup_url']]]);
    }
}
