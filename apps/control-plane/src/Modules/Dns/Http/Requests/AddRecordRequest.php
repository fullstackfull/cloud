<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;

/**
 * The shape of a record. What it may *say* is decided in the domain.
 *
 * The split matters. Everything here is true of every record of its type —
 * a TTL is a number, a type is one of six — and everything the domain checks
 * needs to know what an A record means or what else is in the zone. Putting
 * the second kind here would mean a second implementation of it for the
 * reconciler and the jobs, which is how two answers to one question begin.
 */
final class AddRecordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(DnsRecordType::class)],
            'name' => ['required', 'string', 'max:253'],

            /*
             * Required for every type except CAA, which carries three fields
             * instead. Sending both is not an error — the platform composes
             * the content from `data` for CAA and ignores what was sent — but
             * sending neither is.
             */
            'content' => ['required_unless:type,CAA', 'nullable', 'string', 'max:2048'],

            'ttl' => ['sometimes', 'integer'],
            'priority' => ['sometimes', 'nullable', 'integer', 'between:0,65535'],

            'data' => ['required_if:type,CAA', 'sometimes', 'array'],
            'data.flags' => ['required_if:type,CAA', 'integer', 'between:0,255'],
            'data.tag' => ['required_if:type,CAA', 'string', 'max:16'],
            'data.value' => ['required_if:type,CAA', 'string', 'max:255'],
        ];
    }

    public function type(): DnsRecordType
    {
        return DnsRecordType::from((string) $this->input('type'));
    }

    public function ttl(): int
    {
        return (int) $this->input('ttl', DnsRecord::AUTOMATIC_TTL);
    }

    public function priority(): ?int
    {
        $priority = $this->input('priority');

        return $priority === null || $priority === '' ? null : (int) $priority;
    }

    /**
     * @return array<string, mixed>
     */
    public function structured(): array
    {
        if ($this->type() !== DnsRecordType::CAA) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = (array) $this->input('data', []);

        return [
            'flags' => (int) ($data['flags'] ?? 0),
            'tag' => (string) ($data['tag'] ?? ''),
            'value' => (string) ($data['value'] ?? ''),
        ];
    }

    /**
     * What goes in the content column.
     *
     * For CAA this is the presentation form assembled from the three fields,
     * so that everything in the table can be read, compared and deduplicated
     * the same way — and so that a customer looking at their zone sees the
     * string every other DNS tool would show them.
     */
    public function content(): string
    {
        if ($this->type() !== DnsRecordType::CAA) {
            return (string) $this->input('content', '');
        }

        $data = $this->structured();

        return sprintf('%d %s "%s"', $data['flags'], $data['tag'], $data['value']);
    }
}
