<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Identity\Domain\Enums\CustomerCapability;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The role explanation a customer reads and the authorization the server
 * enforces are the same list, in both directions.
 *
 * The audit's finding was that the team screen had no permission explanation
 * at all. The obvious fix — a table in React of roles and ticks — is worse
 * than nothing, because it is a promise maintained by hand in a file nobody
 * touches when a permission moves. A customer then assigns "Billing" on the
 * strength of a row that has been wrong for two releases.
 *
 * So the matrix is derived from `CustomerRole::permissions()`, and this gate
 * holds the derivation honest:
 *
 *  1. Every capability the API publishes names a permission that at least one
 *     endpoint actually checks. A published capability nobody enforces is a
 *     sentence the server does not honour.
 *  2. Every permission an endpoint checks is published. An enforced permission
 *     nobody publishes is a refusal the customer cannot have anticipated.
 *  3. Every enforced permission is held by at least one role — otherwise it is
 *     a door with no key.
 *  4. The permissions the role model declares but nothing enforces are exactly
 *     the two that are known and written down. A third one appearing fails
 *     this test until somebody says why it is not published.
 *
 * The enforced set is read out of the controllers rather than out of a list,
 * because a list is the thing that drifts.
 */
final class EveryPublishedCapabilityIsEnforcedTest extends TestCase
{
    /**
     * Permissions the role model declares that no endpoint checks, each with
     * the reason it is not published to customers.
     *
     * Not a suppression list: these are product facts. If either becomes
     * enforceable — a self-service closure flow, a stored payment instrument —
     * it stops being listed here and starts being a published capability, and
     * this test is what makes that a deliberate step.
     */
    private const array DECLARED_BUT_UNENFORCED = [
        // There is no self-service account closure. Closing an account is a
        // support conversation at launch.
        'customer.close',
        // The platform stores no payment instrument; the gateway holds it.
        'billing.methods.manage',
    ];

    #[Test]
    public function every_published_capability_names_a_permission_an_endpoint_checks(): void
    {
        $enforced = self::enforcedPermissions();

        $this->assertNotSame([], $enforced, 'No enforced permissions were found; the scan has drifted.');

        $unenforced = array_values(array_diff(CustomerCapability::permissions(), $enforced));

        $this->assertSame(
            [],
            $unenforced,
            "These capabilities are published to customers and enforced by no endpoint:\n"
            .implode("\n", $unenforced),
        );
    }

    #[Test]
    public function every_permission_an_endpoint_checks_is_published(): void
    {
        $unpublished = array_values(array_diff(
            self::enforcedPermissions(),
            CustomerCapability::permissions(),
        ));

        $this->assertSame(
            [],
            $unpublished,
            "These permissions refuse customer requests and no capability explains them:\n"
            .implode("\n", $unpublished),
        );
    }

    #[Test]
    public function every_enforced_permission_is_held_by_at_least_one_role(): void
    {
        $unreachable = array_values(array_filter(
            self::enforcedPermissions(),
            static fn (string $permission): bool => ! array_filter(
                CustomerRole::cases(),
                static fn (CustomerRole $role): bool => $role->can($permission),
            ),
        ));

        $this->assertSame(
            [],
            $unreachable,
            "These permissions are checked and no role can satisfy them:\n".implode("\n", $unreachable),
        );
    }

    #[Test]
    public function the_permissions_no_endpoint_checks_are_the_two_that_are_written_down(): void
    {
        $declared = [];

        foreach (CustomerRole::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $declared[$permission] = true;
            }
        }

        $unenforced = array_values(array_diff(array_keys($declared), self::enforcedPermissions()));
        sort($unenforced);

        $expected = self::DECLARED_BUT_UNENFORCED;
        sort($expected);

        $this->assertSame(
            $expected,
            $unenforced,
            'The role model declares a permission nothing enforces, and the reason is not recorded. '
            ."Either enforce it, remove it, or add it to DECLARED_BUT_UNENFORCED with its reason.\n"
            .'Found: '.implode(', ', $unenforced),
        );
    }

    #[Test]
    public function no_unenforced_permission_is_published_as_a_capability(): void
    {
        $published = array_values(array_intersect(
            CustomerCapability::permissions(),
            self::DECLARED_BUT_UNENFORCED,
        ));

        $this->assertSame(
            [],
            $published,
            'A permission recorded as unenforced is being published as a customer capability: '
            .implode(', ', $published),
        );
    }

    /**
     * Every permission string a controller passes to `authoriseWithinAccount`.
     *
     * @return list<string>
     */
    private static function enforcedPermissions(): array
    {
        $found = [];

        foreach (self::controllerSources() as $source) {
            preg_match_all(
                "/authoriseWithinAccount\(\s*\\\$request\s*,\s*'([a-z][a-z0-9_.]*)'\s*\)/",
                $source,
                $matches,
            );

            foreach ($matches[1] as $permission) {
                $found[$permission] = true;
            }
        }

        $permissions = array_keys($found);
        sort($permissions);

        return $permissions;
    }

    /**
     * @return iterable<string>
     */
    private static function controllerSources(): iterable
    {
        $root = __DIR__.'/../../src';

        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents !== false) {
                yield $contents;
            }
        }
    }
}
