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
        /*
         * Provider adapters were added after this gate found nothing and the
         * product still had a hole in it: `changePackage` was implemented in
         * both control panel adapters, tested against recorded HTTP exchanges,
         * and called by nothing, so an upgraded hosting customer kept their
         * old quota for ever. An adapter method with no caller is a provider
         * capability the platform advertises to itself and does not have.
         *
         * Written as the full directory rather than the bare word, because a
         * module was later named Providers and the bare word matched every
         * file in it. That pulled models, resources, requests and controllers
         * into a gate whose whole premise is that those are excluded — their
         * public surface is read by serialisers, casts, routers and the
         * framework in ways no textual search can see. The visible symptom was
         * ProviderController::enable being reported as dead while
         * ServerController::classify, identical in every relevant way, was
         * not: the only difference between them was which module they sat in.
         *
         * The layer being described is the adapter directory. Every vendor
         * adapter in this codebase lives at Modules/<Module>/Infrastructure/
         * Providers/, and that is what this now matches.
         */
        'Infrastructure/Providers',
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
        // Called by PHP itself when a value is dumped, exactly as __toString
        // is when one is printed. A DTO that redacts a secret in __debugInfo
        // has no caller to find and is doing the most important job in the
        // class.
        '__debugInfo',
        // An adapter's statement of which vendor it speaks for, read by the
        // factory that built it. The same shape as name() and kind().
        'panel',
        // A framework hook the service provider's parent calls. Overriding it
        // to return false is how event discovery is switched off, and the
        // override having no caller in this codebase is the point of it.
        'shouldDiscoverEvents',
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
         * ------------------------------------------------------------------
         * Provider contract methods with no production caller
         * ------------------------------------------------------------------
         *
         * Each is one half of a capability the platform does not offer yet,
         * and each is listed with the half that is missing rather than
         * deleted: a provider contract is a description of what a vendor can
         * do, and removing the description does not remove the gap — it hides
         * that the gap has a shape. What is not allowed is an entry that says
         * "later".
         *
         * Fakes are not listed. Their extra surface is test scaffolding, and
         * their contract methods are covered by the real adapters' entries
         * below.
         */

        // Finding which of an account's zones would serve a given name. The
        // platform holds its own zones in its own table and matches names
        // against them there, so there is nothing to ask a provider: the
        // question "who serves this name" is one this platform can already
        // answer about itself, and asking would be a round trip to be told
        // what it just looked up.
        'CloudflareDnsProvider::zoneFor' => 'the platform matches names against the zones it holds, in its own table',

        // Resetting a hosting account's password. There is no customer path
        // and no operator path: a customer reaches their panel through
        // single sign-on, so the password is never theirs to change.
        'CpanelHostingProvider::changePassword' => 'no password-reset path; customers reach the panel through SSO',
        'DirectAdminHostingProvider::changePassword' => 'no password-reset path; customers reach the panel through SSO',

        /*
         * `listAccounts` and `getTask` were both here until Phase 30A+.
         * `ReconcileHostingNodes` calls the first and `PollProviderTasks` the
         * second, so neither is reserved any more. Noted rather than deleted:
         * the shape of this list is the record of which halves of the product
         * were missing, and when they stopped being.
         */

        // A hard reset of a virtual machine. The customer API offers stop,
        // shutdown, reboot and start; a reset that discards whatever the guest
        // had not flushed is not one of them.
        'ProxmoxComputeProvider::resetVm' => 'the customer API deliberately offers no hard reset for a VPS',

        /*
         * Cutting a physical machine's power at the rail, and reading its boot
         * order. The power action deliberately sends an ACPI shutdown instead
         * — the hard cut costs a customer whatever the host had not flushed —
         * and the boot order is read only as part of arming an install, which
         * the reinstall handler does through authorisation rather than by
         * inspection.
         */
        'IpmiDedicatedProvider::powerOff' => 'the customer API sends an ACPI shutdown; the hard cut is an operator decision',
        'RedfishDedicatedProvider::powerOff' => 'the customer API sends an ACPI shutdown; the hard cut is an operator decision',
        'IpmiDedicatedProvider::bootOrder' => 'boot order is set for an install and never read back',
        'RedfishDedicatedProvider::bootOrder' => 'boot order is set for an install and never read back',

        /*
         * Backup retention and verification.
         *
         * Backups are taken, tracked and restored from. Nothing prunes an
         * expired archive at the provider and nothing asks the provider to
         * verify one — the `verified` column is written by the reconciler from
         * what the provider reports, not by a verification this platform
         * starts.
         */
        'ProxmoxBackupProvider::startVerification' => 'the platform does not start verifications; it records what it is told',
        'ProxmoxBackupProvider::supportsVerification' => 'the platform does not start verifications; it records what it is told',

        /*
         * `WalletLedger::debit` was here for the same reason and left for the
         * same one: `PayInvoiceFromWallet` spends the balance now.
         */

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

            /*
             * A fake adapter's extra surface is test scaffolding — a way for a
             * test to arrange a refusal or observe what was asked — and
             * demanding a production caller for it would be demanding that
             * production code use the fakes. The contract methods a fake
             * implements are covered by the real adapters beside it.
             */
            if (str_contains($file, '/Fake')) {
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
    public function no_reserved_method_has_quietly_acquired_a_caller(): void
    {
        /*
         * The other way this list rots. An entry describes a product gap; when
         * somebody closes the gap the entry becomes a lie that hides the next
         * finding, and nothing else would notice — the method now has a caller,
         * so the main assertion skips it either way.
         */
        $haystacks = [];

        foreach (self::filesIn(['src', 'app', 'routes', 'config', 'database/seeders']) as $file) {
            $haystacks[$file] = self::stripComments((string) file_get_contents($file));
        }

        $live = [];

        foreach (array_keys(self::RESERVED) as $key) {
            [, $method] = explode('::', $key, 2);

            if (self::isCalled($method, $haystacks)) {
                $live[] = $key;
            }
        }

        $this->assertSame([], $live, sprintf(
            "These methods are excused as unreachable and something now calls them:\n  %s",
            implode("\n  ", $live),
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
