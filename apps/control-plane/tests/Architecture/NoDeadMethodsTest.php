<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The same hunt as NoDeadCapabilitiesTest, one level down.
 *
 * Phase 29 proved that a class nothing references is a capability nobody can
 * use. Phase 30A found the sequel: a class that IS referenced can still carry
 * public methods nothing calls — a second way to issue an invoice, a debit
 * that balances a credit nobody can spend, a release that pairs with a
 * termination the platform never performs. Each of those reads as a working
 * feature in the code and does not exist in the product.
 *
 * The first run found twenty-two. Thirteen survived the false-positive filters
 * below; eight were genuinely obsolete and were deleted, two were real
 * capabilities and were wired up — a wallet integrity check and a per-subnet
 * address capacity report — and three remain, each the unbuilt half of a
 * lifecycle, each listed here with what is missing.
 *
 * ---------------------------------------------------------------------------
 * What counts as a caller
 * ---------------------------------------------------------------------------
 *
 * Comments are stripped first, for the reason the class-level gate strips
 * them: several of these methods are named only in a docblock explaining that
 * nothing calls them yet.
 *
 * The declaring file counts. A method used only by its own class is reachable
 * whenever the class is, and excluding it would flag every private-in-effect
 * helper.
 *
 * A method named as a string in its own class is a caller too: event
 * subscribers register their handlers by name, and `'invoicePaid'` in a
 * subscribe() map is as real a call as `$this->invoicePaid()`.
 *
 * Tests are not searched at all. A method whose only caller is its own test is
 * exactly what this is hunting.
 */
final class NoDeadMethodsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Directories whose classes carry operational behaviour.
     *
     * Value objects, models, resources and requests are deliberately excluded:
     * their public surface is read by serialisers, casts and the framework in
     * ways no textual search can see, and including them would produce a list
     * nobody reads.
     *
     * @var list<string>
     */
    private const array LAYERS = [
        'Actions', 'Services', 'Handlers', 'Listeners', 'Jobs', 'StateMachines', 'Registries',
    ];

    /**
     * Names that are entry points by contract or by framework convention.
     *
     * @var list<string>
     */
    private const array CONTRACTUAL = [
        '__construct', '__invoke', '__toString', 'handle', 'execute', 'kind', 'name', 'collect',
        'subscribe', 'toArray', 'rules', 'messages', 'casts', 'boot', 'register', 'authorize',
        'prepareForValidation', 'via', 'toMail', 'failed', 'backoff', 'retryUntil', 'uniqueId',
        'middleware', 'tags', 'newFactory', 'getMorphClass', 'resolve', 'answers', 'protocol',
        'definition', 'run', 'up', 'down',
    ];

    /**
     * Methods with no production caller that are staying anyway, and why.
     *
     * Every entry names a product gap rather than excusing one. These are not
     * "we might need it later" — each is one half of a mechanism whose other
     * half the platform does not yet have, and deleting it would not close the
     * gap, it would only hide that the gap has a shape.
     *
     * @var array<string, string>
     */
    private const array RESERVED = [
        /*
         * Releasing an address back to its pool, with the quarantine period
         * that stops the next customer inheriting somebody else's blocklist
         * entries and abuse reports.
         *
         * Its caller would be service termination, and the platform does not
         * terminate services: a customer can cancel a subscription, billing
         * stops, and the service and its addresses stay exactly where they
         * are. That is reported as a product gap rather than fixed here —
         * automated termination destroys customer data and belongs in a phase
         * that can prove it end to end.
         */
        'IpAllocator::releaseAssignment' => 'service termination is not automated',

        /*
         * Taking money out of a wallet.
         *
         * Wallets are credited — an overpayment lands in one — and nothing can
         * spend the balance: no customer path, no operator path. A customer
         * with credit can see it and cannot use it. Deleting the debit would
         * leave the platform able only to take money in, which is a worse
         * shape, so it stays and the gap is stated.
         */
        'WalletLedger::debit' => 'wallet credit cannot be spent by anyone yet',

        /*
         * Issuing an invoice from a priced result rather than from line
         * drafts.
         *
         * Two ways into the same table, and only the draft path has callers.
         * Removing this one means porting the invoice tests — which cover tax
         * and percentage discounts through the pricing engine — onto drafts,
         * and that is billing-sensitive work that does not belong at the end
         * of a phase about product closure.
         */
        'IssueInvoice::fromPricedOrder' => 'a second invoice-issuing path; removal needs the billing tests ported',
    ];

    #[Test]
    public function every_public_operational_method_has_a_production_caller(): void
    {
        $haystacks = [];

        foreach (self::filesIn(['src', 'app', 'routes', 'config', 'database/seeders']) as $file) {
            $haystacks[$file] = self::stripComments((string) file_get_contents($file));
        }

        $dead = [];

        foreach (self::filesIn(['src', 'app']) as $file) {
            if (! self::isOperational($file)) {
                continue;
            }

            $code = self::stripComments((string) file_get_contents($file));

            if (! preg_match('/\b(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $code, $matches)) {
                continue;
            }

            $class = $matches[1];

            preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)\s*\(/', $code, $methods);

            foreach ($methods[1] as $method) {
                if (in_array($method, self::CONTRACTUAL, true)) {
                    continue;
                }

                if (self::isCalled($method, $haystacks) || self::isSubscribed($method, $code)) {
                    continue;
                }

                $key = $class.'::'.$method;

                if (array_key_exists($key, self::RESERVED)) {
                    continue;
                }

                $dead[] = $key;
            }
        }

        sort($dead);

        $this->assertSame([], $dead, sprintf(
            'These public methods exist and nothing in the application calls them. Each one presents as a working '
            .'capability and is not one — wire it up, delete it, or add it to RESERVED with the product gap it '
            ."represents:\n  %s",
            implode("\n  ", $dead),
        ));
    }

    #[Test]
    public function every_reserved_method_still_exists(): void
    {
        /*
         * A stale entry is worse than no list: it outlives the gap it
         * describes, and the next real finding hides among the excuses.
         */
        $stale = [];

        $sources = [];

        foreach (self::filesIn(['src', 'app']) as $file) {
            $sources[] = (string) file_get_contents($file);
        }

        $all = implode("\n", $sources);

        foreach (array_keys(self::RESERVED) as $key) {
            [, $method] = explode('::', $key);

            if (! str_contains($all, 'function '.$method.'(')) {
                $stale[] = $key;
            }
        }

        $this->assertSame([], $stale, 'These methods are listed as reserved and no longer exist.');
    }

    /**
     * @param  array<string, string>  $haystacks
     */
    private static function isCalled(string $method, array $haystacks): bool
    {
        $pattern = '/(->|::)'.preg_quote($method, '/').'\s*\(/';

        foreach ($haystacks as $code) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Event subscribers name their handlers as strings.
     */
    private static function isSubscribed(string $method, string $code): bool
    {
        return preg_match("/=>\s*'".preg_quote($method, '/')."'/", $code) === 1;
    }

    private static function isOperational(string $file): bool
    {
        foreach (self::LAYERS as $layer) {
            if (str_contains($file, '/'.$layer.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private static function filesIn(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $path = self::ROOT.'/'.$directory;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function stripComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= "\n";

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
