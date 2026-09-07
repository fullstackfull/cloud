<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;

/**
 * The confirmation, an optional image, optional keys, and an idempotency key.
 *
 * `confirm_hostname` is a string that must equal the machine's own hostname,
 * and it is a string on purpose. The obvious alternative — `"confirm": true` —
 * is a field that every generated client sets in its constructor, that every
 * convenience wrapper defaults, and that a retry loop resends without a person
 * ever seeing it. Typing a hostname cannot be done by accident.
 *
 * The comparison itself is not here: this class only guarantees the field was
 * sent and is a plausible hostname. Whether it names *this* machine is decided
 * by RequestVpsReinstall, so an operator tool or a support script calling the
 * action directly is held to the same proof.
 *
 * There is no `preserve_data`, no `keep_disks` and no `snapshot_first`. A
 * reinstall erases the disk; an option that sometimes did not would make the
 * dangerous operation the one whose behaviour depends on a flag.
 */
final class ReinstallRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    /**
     * One OpenSSH public key: a type, a base64 blob, an optional comment, and
     * nothing that could start a second line.
     *
     * The comment is allowed to be almost anything a person would type, but
     * not a control character: those are what turn one entry into two keys, or
     * into a stray CR in a file a guest parses line by line.
     */
    private const string OPENSSH_PUBLIC_KEY = '/\A[A-Za-z0-9@.\-]{1,64} [A-Za-z0-9+\/]+={0,3}(?: [^\x00-\x1f\x7f]{0,255})?\z/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_hostname' => ['required', 'string', 'max:255'],

            /*
             * The image to lay down. Optional — omitted means "the same one
             * again" — and resolved by the controller against what is
             * installable on this machine's own cluster, because an id in a
             * body is otherwise a request for an image staged on somebody
             * else's hardware.
             */
            'template_id' => ['sometimes', 'nullable', 'string', 'ulid'],

            /*
             * Bounded: a key list is a key list, not an upload. Each one is
             * written into cloud-init on first boot.
             *
             * One key, on one line. CloudInitConfig joins this list with "\n"
             * to build the authorized_keys block, so an entry carrying its own
             * newline is several keys wearing one array slot — which also
             * makes the count bound above decorative. The shape is checked
             * here rather than trusted later: by the time the string reaches a
             * guest it is a line in the file that decides who may log in.
             */
            'ssh_keys' => ['sometimes', 'array', 'max:20'],
            'ssh_keys.*' => [
                'required',
                'string',
                'max:4096',
                'regex:'.self::OPENSSH_PUBLIC_KEY,
            ],
        ] + $this->idempotencyKeyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessages() + [
            'confirm_hostname.required' => 'Reinstalling erases every disk on this machine. Send confirm_hostname with the machine\'s hostname to confirm.',
            'ssh_keys.*.regex' => 'Each entry must be one OpenSSH public key on one line, in the form "type base64-key comment".',
        ];
    }

    public function confirmation(): string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return (string) $validated['confirm_hostname'];
    }

    public function templateId(): ?string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $id = $validated['template_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<string>
     */
    public function sshKeys(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $keys = $validated['ssh_keys'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }
}
