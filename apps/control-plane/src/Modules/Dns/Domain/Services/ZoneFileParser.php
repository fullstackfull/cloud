<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Services;

use Lynomia\Modules\Dns\Domain\DTOs\ParsedProblem;
use Lynomia\Modules\Dns\Domain\DTOs\ParsedRecord;
use Lynomia\Modules\Dns\Domain\DTOs\ParsedZone;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\ZoneFileRefusedException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;

/**
 * A BIND-compatible zone file, read into records the platform can judge.
 *
 * ===========================================================================
 * WHAT IS PARSED, AND WHAT IS NOT
 * ===========================================================================
 *
 * Read: comments, `$ORIGIN`, `$TTL`, parentheses spanning lines, quoted
 * strings with escapes, the optional class, the optional TTL in seconds or
 * BIND units, owner names relative to the origin, `@`, a blank owner meaning
 * "the previous one", and the six record types this platform holds — A,
 * AAAA, CNAME, MX, TXT, CAA.
 *
 * Refused, with the line: `$INCLUDE` (a file path is not something a zone
 * file gets to name on this platform), `$GENERATE` (a loop that could
 * write thousands of rows from one line), any other directive, any record
 * type the platform does not hold, and any line the grammar cannot read.
 *
 * Ignored, with the line and the reason: the SOA, and NS records at the
 * apex. Every exported zone carries them and this platform's provider
 * owns both; a file that carries them is not wrong, it is just not the
 * customer's to set here. They are listed so nothing is silently dropped.
 *
 * ===========================================================================
 * BOUNDS
 * ===========================================================================
 *
 * The parser refuses before it reads: an input over the byte limit, over
 * the line limit, with a line over the length limit, that is not UTF-8, or
 * that carries control characters other than tab and newline. A zone file
 * is small; one that is not is not a zone file.
 */
final readonly class ZoneFileParser
{
    private const int MAX_BYTES = 262_144;

    private const int MAX_LINES = 5_000;

    private const int MAX_LINE_LENGTH = 4_096;

    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400, 'w' => 604_800];

    /**
     * @throws ZoneFileRefusedException when the input is not something to read at all
     */
    public function parse(string $text, string $zoneName): ParsedZone
    {
        $this->assertReadable($text);

        $origin = strtolower(trim($zoneName, '.'));
        $defaultTtl = DnsRecord::AUTOMATIC_TTL;
        $previousOwner = $origin;

        $records = [];
        $refused = [];
        $ignored = [];

        foreach ($this->logicalLines($text) as [$lineNumber, $raw]) {
            $line = trim($this->stripComment($raw));

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '$')) {
                $directive = strtoupper(strtok($line, " \t") ?: $line);
                $argument = trim(substr($line, strlen($directive)));

                if ($directive === '$ORIGIN') {
                    $candidate = strtolower(rtrim($argument, '.'));
                    if ($candidate === '' || ! $this->within($candidate, $origin)) {
                        $refused[] = ParsedProblem::at($lineNumber, $raw, sprintf('$ORIGIN must be %s or a name under it.', $origin));

                        continue;
                    }
                    $origin = $candidate;
                    $previousOwner = $origin;

                    continue;
                }

                if ($directive === '$TTL') {
                    $seconds = $this->ttl($argument);
                    if ($seconds === null) {
                        $refused[] = ParsedProblem::at($lineNumber, $raw, '$TTL is not a duration the platform understands (seconds, or 30m, 1h, 1d, 1w).');

                        continue;
                    }
                    $defaultTtl = $seconds;

                    continue;
                }

                $refused[] = ParsedProblem::at($lineNumber, $raw, match ($directive) {
                    '$INCLUDE' => '$INCLUDE names a file on somebody\'s disk; a zone file may not do that here.',
                    '$GENERATE' => '$GENERATE writes many records from one line; write them out.',
                    default => sprintf('%s is not a directive this platform reads.', $directive),
                });

                continue;
            }

            $parsed = $this->record($lineNumber, $raw, $line, $origin, $defaultTtl, $previousOwner);

            if ($parsed instanceof ParsedProblem) {
                if (str_starts_with($parsed->reason, 'ignored:')) {
                    $ignored[] = new ParsedProblem($parsed->line, $parsed->text, substr($parsed->reason, strlen('ignored: ')));
                } else {
                    $refused[] = $parsed;
                }

                continue;
            }

            $previousOwner = $parsed->name;
            $records[] = $parsed;
        }

        return new ParsedZone($origin, $records, $refused, $ignored);
    }

    private function assertReadable(string $text): void
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw ZoneFileRefusedException::tooLarge(self::MAX_BYTES);
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw ZoneFileRefusedException::notText('the input is not valid UTF-8');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw ZoneFileRefusedException::notText('the input carries control characters');
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        if (count($lines) > self::MAX_LINES) {
            throw ZoneFileRefusedException::tooLarge(self::MAX_BYTES, self::MAX_LINES);
        }

        foreach ($lines as $index => $line) {
            if (strlen($line) > self::MAX_LINE_LENGTH) {
                throw ZoneFileRefusedException::lineTooLong($index + 1, self::MAX_LINE_LENGTH);
            }
        }
    }

    /**
     * Physical lines joined where parentheses say so, quotes respected.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function logicalLines(string $text): array
    {
        $out = [];
        $buffer = '';
        $depth = 0;
        $startedAt = 0;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $physical) {
            $number = $index + 1;

            if ($buffer === '') {
                $startedAt = $number;
            }

            $buffer .= ($buffer === '' ? '' : ' ').$physical;
            $depth += $this->parenthesisDelta($this->stripComment($physical));

            if ($depth <= 0) {
                $out[] = [$startedAt, $buffer];
                $buffer = '';
                $depth = 0;
            }
        }

        if ($buffer !== '') {
            $out[] = [$startedAt, $buffer.' )'];
        }

        return $out;
    }

    private function parenthesisDelta(string $line): int
    {
        $delta = 0;
        $quoted = false;

        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $char = $line[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"') {
                $quoted = ! $quoted;

                continue;
            }

            if (! $quoted && $char === '(') {
                $delta++;
            } elseif (! $quoted && $char === ')') {
                $delta--;
            }
        }

        return $delta;
    }

    private function stripComment(string $line): string
    {
        $quoted = false;

        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $char = $line[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"') {
                $quoted = ! $quoted;

                continue;
            }

            if (! $quoted && $char === ';') {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $line): array
    {
        $tokens = [];
        $current = '';
        $quoted = false;
        $inToken = false;

        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $char = $line[$i];

            if ($char === '\\' && $i + 1 < $n) {
                $current .= $line[$i + 1];
                $inToken = true;
                $i++;

                continue;
            }

            if ($char === '"') {
                $quoted = ! $quoted;
                $inToken = true;

                continue;
            }

            if (! $quoted && ($char === ' ' || $char === "\t" || $char === '(' || $char === ')')) {
                if ($inToken) {
                    $tokens[] = $current;
                    $current = '';
                    $inToken = false;
                }

                continue;
            }

            $current .= $char;
            $inToken = true;
        }

        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    private function record(int $lineNumber, string $raw, string $line, string $origin, int $defaultTtl, string $previousOwner): ParsedRecord|ParsedProblem
    {
        // A line that starts with whitespace has no owner: it belongs to the
        // previous one. Read before trimming.
        $ownerless = preg_match('/^\s/', $raw) === 1;
        $tokens = $this->tokens($line);

        if ($tokens === []) {
            return ParsedProblem::at($lineNumber, $raw, 'The line could not be read.');
        }

        $owner = $ownerless ? $previousOwner : $this->resolveOwner(array_shift($tokens), $origin);

        if ($owner === null) {
            return ParsedProblem::at($lineNumber, $raw, 'The owner name is not a name the platform can hold.');
        }

        // [ttl] [class] type rdata..., in either order for ttl and class.
        $ttl = $defaultTtl;
        $consumed = 0;

        for ($round = 0; $round < 2 && $tokens !== []; $round++) {
            $head = $tokens[0];
            if (strtoupper($head) === 'IN') {
                array_shift($tokens);
                $consumed++;

                continue;
            }
            if (in_array(strtoupper($head), ['CH', 'HS', 'CS'], true)) {
                return ParsedProblem::at($lineNumber, $raw, 'Only the IN class is served.');
            }
            $seconds = $this->ttl($head);
            if ($seconds !== null && ! DnsRecordType::tryFrom(strtoupper($head)) instanceof DnsRecordType) {
                array_shift($tokens);
                $ttl = $seconds;
                $consumed++;
            }
        }

        if ($tokens === []) {
            return ParsedProblem::at($lineNumber, $raw, 'The record has no type.');
        }

        $typeToken = strtoupper(array_shift($tokens));

        if ($typeToken === 'SOA') {
            return ParsedProblem::at($lineNumber, $raw, 'ignored: The SOA belongs to the nameservers that serve the zone; the platform sets it.');
        }

        if ($typeToken === 'NS' && $owner === $origin) {
            return ParsedProblem::at($lineNumber, $raw, 'ignored: The apex NS records are the platform\'s own nameservers; delegation is set at the registrar.');
        }

        $type = DnsRecordType::tryFrom($typeToken);

        if (! $type instanceof DnsRecordType) {
            return ParsedProblem::at($lineNumber, $raw, sprintf('%s records are not held here. The platform serves A, AAAA, CNAME, MX, TXT and CAA.', $typeToken));
        }

        return match ($type) {
            DnsRecordType::A, DnsRecordType::AAAA => count($tokens) === 1
                ? new ParsedRecord($lineNumber, $type, $owner, $tokens[0], $ttl, null, [])
                : ParsedProblem::at($lineNumber, $raw, sprintf('An %s record takes exactly one address.', $type->value)),
            DnsRecordType::CNAME => count($tokens) === 1
                ? new ParsedRecord($lineNumber, $type, $owner, $this->target($tokens[0], $origin), $ttl, null, [])
                : ParsedProblem::at($lineNumber, $raw, 'A CNAME takes exactly one target.'),
            DnsRecordType::MX => count($tokens) === 2 && ctype_digit($tokens[0])
                ? new ParsedRecord($lineNumber, $type, $owner, $this->target($tokens[1], $origin), $ttl, (int) $tokens[0], [])
                : ParsedProblem::at($lineNumber, $raw, 'An MX record is a priority followed by one exchange name.'),
            DnsRecordType::TXT => $tokens === []
                ? ParsedProblem::at($lineNumber, $raw, 'A TXT record needs a value.')
                : new ParsedRecord($lineNumber, $type, $owner, implode('', $tokens), $ttl, null, []),
            DnsRecordType::CAA => count($tokens) === 3 && ctype_digit($tokens[0])
                ? new ParsedRecord(
                    $lineNumber,
                    $type,
                    $owner,
                    sprintf('%d %s "%s"', (int) $tokens[0], strtolower($tokens[1]), $tokens[2]),
                    $ttl,
                    null,
                    ['flags' => (int) $tokens[0], 'tag' => strtolower($tokens[1]), 'value' => $tokens[2]],
                )
                : ParsedProblem::at($lineNumber, $raw, 'A CAA record is flags, a tag and a quoted value.'),
        };
    }

    private function resolveOwner(string $token, string $origin): ?string
    {
        if ($token === '@') {
            return $origin;
        }

        $token = strtolower($token);

        if (str_ends_with($token, '.')) {
            $absolute = rtrim($token, '.');

            return $absolute === '' ? null : $absolute;
        }

        return $token.'.'.$origin;
    }

    /**
     * A CNAME or MX target: absolute when it ends with a dot, otherwise
     * relative to the origin like an owner. Returned without the dot, which
     * is how the platform stores every name.
     */
    private function target(string $token, string $origin): string
    {
        $token = strtolower($token);

        if ($token === '@') {
            return $origin;
        }

        // An address where a name belongs is left as the address, so the
        // record rules refuse it in words rather than the parser hiding it
        // inside a name that happens to be well-formed.
        if (filter_var($token, FILTER_VALIDATE_IP) !== false) {
            return $token;
        }

        return str_ends_with($token, '.') ? rtrim($token, '.') : $token.'.'.$origin;
    }

    private function ttl(string $token): ?int
    {
        if (ctype_digit($token)) {
            return (int) $token;
        }

        if (preg_match('/^(\d+)([smhdw])$/i', $token, $m) === 1) {
            return (int) $m[1] * self::UNITS[strtolower($m[2])];
        }

        return null;
    }

    private function within(string $name, string $zone): bool
    {
        return $name === $zone || str_ends_with($name, '.'.$zone);
    }
}
