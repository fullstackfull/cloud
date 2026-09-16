<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Naming;

use Stringable;

/**
 * A machine-safe logical name: the identifier a thing keeps.
 *
 * ===========================================================================
 * WHAT THIS IS FOR
 * ===========================================================================
 *
 * The strings this platform puts in URLs, log lines, metric labels, IaC
 * variables, provider requests and foreign keys — `kw-central-1`,
 * `debian-stable`, `ref-node-alpha-1-a`. They are read by machines and typed by
 * people, which is the whole reason they are constrained: lower case so that
 * two spellings are never two things, ASCII so that a terminal, a YAML file and
 * a DNS label all carry them unchanged, dashes so there is one separator rather
 * than three.
 *
 * What it is NOT for: display names, which carry human wording and Arabic and
 * are free to be renamed; hostnames, which have their own rules
 * ({@see DnsName}); and provider-native identifiers, which belong to somebody
 * else's naming scheme and are stored exactly as that somebody spells them.
 *
 * ===========================================================================
 * NORMALISATION AND VALIDATION ARE TWO METHODS ON PURPOSE
 * ===========================================================================
 *
 * {@see self::normalize()} answers "what would this become", for a creation
 * form that shows an operator the identifier they are about to get.
 * {@see self::problemWith()} answers "is this one", for everything that reads a
 * value that already exists.
 *
 * They are never composed into one silent step. An identifier already written
 * into orders, audit entries and monitoring is not a string the platform may
 * quietly rewrite on the way past — a row that does not comply is a finding for
 * somebody to act on, not a migration to perform behind their back.
 *
 * ===========================================================================
 * COLLISIONS
 * ===========================================================================
 *
 * {@see self::collisionKey()} is what uniqueness is judged on, and it is the
 * normalised form rather than the literal. `Node-01`, `node-01` and `NODE 01`
 * are one identity; a platform that stored all three would have three rows, one
 * scheduler that can reach one of them, and no way to say which.
 *
 * @immutable
 */
final readonly class LogicalName implements Stringable
{
    /**
     * Lower-case alphanumeric segments joined by single dashes.
     *
     * No leading or trailing dash, no empty segment, no double dash. Each of
     * those is a string that survives a copy-paste and then reads as a typo
     * forever.
     */
    private const string TOKEN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /**
     * The same shape with the case rule relaxed.
     *
     * For codes people write on physical things. A rack is stencilled `A1`, not
     * `a1`, and a standard that lower-cased the estate's rack labels would
     * disagree with every cabinet in the room. Uniqueness is still judged
     * case-insensitively — `A1` and `a1` are one rack — which is the part that
     * matters and is {@see self::collisionKey()}'s job.
     */
    private const string TOKEN_ANY_CASE = '/\A[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/';

    private function __construct(
        private string $name,
    ) {}

    /**
     * What this input would become as a logical name.
     *
     * Deterministic and lossy in one direction only: it lower-cases, turns
     * runs of anything that is not alphanumeric into a single dash, and trims
     * dashes off the ends. `NODE 01` and `node_01` both become `node-01`, which
     * is the point — they were one identity all along.
     *
     * A string with nothing usable in it normalises to the empty string rather
     * than to an invented value, and the empty string is refused by
     * {@see self::problemWith()}. Silently inventing an identifier for
     * `الرياض` would attach a name nobody typed to a row somebody has to find
     * again.
     */
    public static function normalize(string $candidate): string
    {
        $value = strtolower(trim($candidate));

        // Non-ASCII first: `ø` and `م` are not separators, and mapping them to
        // a dash would join two words that were never joined. They are dropped
        // with the rest of what a logical name cannot carry.
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }

    /**
     * Why this string is not a logical name, or null if it is one.
     *
     * The length ceiling is a parameter because the columns that hold these
     * differ, and a value object that guessed would either refuse identifiers a
     * table accepts or accept identifiers it truncates.
     */
    public static function problemWith(string $candidate, int $maxLength, bool $requireLowerCase = true): ?string
    {
        if (trim($candidate) !== $candidate) {
            return 'it has leading or trailing whitespace';
        }

        if ($candidate === '') {
            return 'it is empty';
        }

        if (strlen($candidate) > $maxLength) {
            return sprintf('it is longer than %d characters', $maxLength);
        }

        if (preg_match('/\A[\x20-\x7E]*\z/', $candidate) !== 1) {
            return 'it is not ASCII — a display name may carry any script, a logical identifier may not';
        }

        if ($requireLowerCase && $candidate !== strtolower($candidate)) {
            return sprintf('it is not lower case — "%s" is the canonical spelling', self::normalize($candidate));
        }

        $token = $requireLowerCase ? self::TOKEN : self::TOKEN_ANY_CASE;

        if (preg_match($token, $candidate) !== 1) {
            return sprintf(
                'it is not alphanumeric segments joined by single dashes — "%s" is the canonical spelling',
                self::normalize($candidate),
            );
        }

        return null;
    }

    /**
     * The value uniqueness is judged on.
     *
     * Two inputs with the same collision key are the same identity however
     * they were typed, and the platform must hold at most one of them.
     */
    public static function collisionKey(string $candidate): string
    {
        return self::normalize($candidate);
    }

    public static function tryFrom(string $candidate, int $maxLength): ?self
    {
        return self::problemWith($candidate, $maxLength) === null
            ? new self($candidate)
            : null;
    }

    public function value(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
