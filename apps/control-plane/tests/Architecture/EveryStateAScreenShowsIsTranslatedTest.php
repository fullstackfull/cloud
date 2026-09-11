<?php

declare(strict_types=1);

namespace Tests\Architecture;

use BackedEnum;
use JsonException;
use Lynomia\Modules\ApiKeys\Domain\Enums\ApiTokenStatus;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState as ChassisPowerState;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Enums\ZoneChangeKind;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;
use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuAllocationState;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuPassthroughMode;
use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ReadinessAnswer;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use Lynomia\Modules\Shared\Domain\Enums\RetryAdvice;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteKind;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every state a customer or an operator can be shown, in both languages.
 *
 * The portal renders a backend enum through `t('status.' + value)` with a
 * fallback that turns `needs_review` into "needs review". That fallback is the
 * problem this gate exists for: a missing translation does not throw, does not
 * warn and does not fail a test. It renders English on the Arabic portal, in
 * snake case, and looks enough like a label that nobody reports it.
 *
 * Phase 30A+ found thirty-four of them at once — every DNS state this phase
 * added, every backup state past `succeeded`, every dedicated reinstall step,
 * both invoice endings, and three of the five drift kinds. All of them had
 * been shipping.
 *
 * The map below is the audit. Each entry says: this namespace in the portal's
 * catalogue renders the values of these enums, so it must carry a string for
 * every case of each — in English and in Arabic. Adding a case to one of these
 * enums fails this test until somebody writes the two sentences a person will
 * read.
 */
final class EveryStateAScreenShowsIsTranslatedTest extends TestCase
{
    private const string LOCALES = __DIR__.'/../../../web/src/i18n/locales';

    /**
     * Portal translation namespace → the enums whose values reach it.
     *
     * `status` is the largest because `StatusBadge` is one component used on
     * seventeen screens: it takes whatever string the API put in a `status` or
     * `state` field and looks it up in one flat namespace. That is a
     * deliberate design — one vocabulary for one concept — and it means every
     * enum any of those screens renders has to be listed here.
     *
     * @var array<string, list<class-string<BackedEnum>>>
     */
    private const array RENDERED = [
        'status' => [
            // StatusBadge, on the customer's screens.
            BackupState::class,
            FileRestoreState::class,
            CountryCurrencyChangeState::class,
            WordPressOperationState::class,
            DnsState::class,
            HostingAccountStatus::class,
            InvoiceStatus::class,
            OrderStatus::class,
            SubscriptionStatus::class,
            ApiTokenStatus::class,
            DedicatedServerStatus::class,
            PowerState::class,
            ChassisPowerState::class,
            ReverseDnsStatus::class,
            // ... and on the operator's, which share the component.
            ClusterStatus::class,
            NodeStatus::class,
            HostingNodeStatus::class,
            ProvisioningJobStatus::class,
            CustomerStatus::class,
            TransactionStatus::class,
            ReinstallState::class,
            DedicatedReinstallState::class,

            /*
             * The one vocabulary for asynchronous work, which every screen
             * that shows a reboot, a rebuild, a build or a feed row renders
             * through the same badge. `indeterminate` and `needs_review` are
             * the two the wave exists for, and both must read as themselves in
             * both languages: a missing Arabic string here would render
             * "needs_review" to the customer it matters most to.
             */
            CustomerOperationState::class,

            /*
             * Domains render three enums through the same badge: what the
             * platform holds, what a registrar answered about a name, and what
             * an attempt to buy one is doing. All three reach a customer, and
             * two of them carry the states that must not be mistaken for
             * anything else — `unknown` on a search and `indeterminate` on a
             * name.
             */
            DomainState::class,
            DomainOperationState::class,
            DomainOperationKind::class,

            // The Control Center. Each concern's states join here in the
            // commit that gives them a screen, not before.
            CredentialState::class,
            LicenceState::class,
            ConnectionState::class,
            ProviderState::class,
            ReadinessState::class,
            ServerState::class,
            ProductReadinessState::class,
            DeploymentState::class,
        ],

        /*
         * The Control Center's own vocabularies. A classification is not a
         * status; a blocker is a reason, shown beside a status; and a
         * capability's "unsupported" means the provider said no, which must
         * not read as the catalogue's "not sold".
         */
        'admin.controlCenter.safety' => [SafetyClass::class],
        'admin.controlCenter.blockers' => [BlockerReason::class],
        'admin.providers.categories' => [ProviderCategory::class],
        'admin.providers.capabilityStates' => [CapabilityState::class],
        'dns.import.kinds' => [ZoneChangeKind::class],
        'wordpress.kinds' => [WordPressSiteKind::class],
        'wordpress.operations.kinds' => [WordPressOperationKind::class],
        'wordpress.push.scopes' => [WordPressPushScope::class],
        'dns.import.modes' => [ZoneImportMode::class],
        'admin.readiness.products' => [Product::class],
        'admin.readiness.software' => [ProductSoftwareState::class],
        'admin.readiness.answerValues' => [ReadinessAnswer::class],
        'admin.servers.passthroughModes' => [GpuPassthroughMode::class],
        'admin.servers.allocationStates' => [GpuAllocationState::class],
        'admin.plans.risks' => [PlanRisk::class],
        'admin.deployments.kinds' => [DeploymentKind::class],

        /*
         * Availability has its own vocabulary rather than sharing the status
         * one. "Unknown" is accurate and useless on a search result: what the
         * customer needs to read is that the registry did not answer and that
         * trying again may work.
         */
        'domains.availabilityStates' => [DomainAvailability::class],
        'billingPeriod' => [BillingPeriod::class],
        'support.statuses' => [TicketStatus::class],
        'notifications.category' => [NotificationCategory::class],
        'notifications.channel' => [NotificationChannel::class],
        'admin.drift.kinds' => [DriftKind::class],
        'admin.drift.severities' => [DriftSeverity::class],
        'admin.drift.statuses' => [DriftStatus::class],
        /*
         * What the customer may do next, rendered as a sentence beside the
         * state rather than as a badge. `support_required` is the one that
         * must never fall back to English: it is the answer on an operation
         * whose result nobody knows.
         */
        'operations.retryAdvice' => [RetryAdvice::class],
        'admin.provisioning.kinds' => [ProvisioningJobKind::class],
        'admin.provisioning.failureClass' => [FailureClass::class],
    ];

    /**
     * Values a namespace deliberately does not carry, with the reason.
     *
     * An entry here is a claim that the value cannot reach that screen. It is
     * not a place to park work: "we have not written it yet" belongs in the
     * catalogue, not here.
     *
     * @var array<string, array<string, string>>
     */
    private const array NOT_SHOWN = [
        'notifications.channel' => [
            'sms' => 'Declared and not implemented; the preferences endpoint offers only implemented channels.',
            'whatsapp' => 'Declared and not implemented.',
            'push' => 'Declared and not implemented.',
        ],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function locales(): array
    {
        return [
            'English' => ['en', 'en.json'],
            'Arabic' => ['ar', 'ar.json'],
        ];
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_value_a_screen_can_render_has_a_string_in_this_language(
        string $locale,
        string $file,
    ): void {
        $catalogue = $this->catalogue($file);
        $missing = [];

        foreach (self::RENDERED as $namespace => $enums) {
            $strings = $this->at($catalogue, $namespace);

            foreach ($enums as $enum) {
                foreach ($enum::cases() as $case) {
                    $value = (string) $case->value;

                    if (isset(self::NOT_SHOWN[$namespace][$value])) {
                        continue;
                    }

                    if (! array_key_exists($value, $strings)) {
                        $missing[] = sprintf('%s.%s (%s)', $namespace, $value, $enum);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), sprintf(
            'The %s catalogue has no string for these states, so a screen showing one renders its raw '.
            "enum value:\n  %s\nAdd them to apps/web/src/i18n/locales/%s.",
            $locale,
            implode("\n  ", array_unique($missing)),
            $file,
        ));
    }

    /**
     * A blocker names what to do next as a translation key, so the screen
     * never decides what "blocked_credentials" means. That key must exist in
     * every language, or the operator reads `controlCenter.guidance.licence`
     * where the instruction should be.
     */
    #[Test]
    #[DataProvider('locales')]
    public function every_next_action_a_blocker_names_is_a_string_in_this_language(
        string $locale,
        string $file,
    ): void {
        $catalogue = $this->catalogue($file);
        $missing = [];

        foreach (BlockerReason::cases() as $reason) {
            $key = $reason->nextAction();
            $namespace = substr($key, 0, (int) strrpos($key, '.'));
            $leaf = substr($key, (int) strrpos($key, '.') + 1);

            if (! array_key_exists($leaf, $this->at($catalogue, $namespace))) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "The %s catalogue has no string for these next actions:\n  %s",
            $locale,
            implode("\n  ", $missing),
        ));
    }

    /**
     * The other direction: a string for a state that no longer exists.
     *
     * Cheaper to find here than to discover from a translator asking what
     * `unknown_at_platform` means. Only the namespaces bound to exactly one
     * enum are checked — `status` is shared by eighteen and legitimately
     * carries values from all of them.
     */
    #[Test]
    public function no_string_names_a_state_that_no_enum_has(): void
    {
        $catalogue = $this->catalogue('en.json');
        $orphans = [];

        foreach (self::RENDERED as $namespace => $enums) {
            if ($namespace === 'status') {
                continue;
            }

            $known = [];

            foreach ($enums as $enum) {
                foreach ($enum::cases() as $case) {
                    $known[] = (string) $case->value;
                }
            }

            foreach (array_keys($this->at($catalogue, $namespace)) as $key) {
                if (! in_array($key, $known, strict: true)) {
                    $orphans[] = $namespace.'.'.$key;
                }
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "These translations name a state no enum has any more:\n  %s",
            implode("\n  ", $orphans),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogue(string $file): array
    {
        $path = self::LOCALES.'/'.$file;

        if (! is_file($path)) {
            throw new RuntimeException("The portal's {$file} is not where this test expects it: {$path}");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The portal's {$file} is not valid JSON: ".$e->getMessage(), previous: $e);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    private function at(array $catalogue, string $namespace): array
    {
        $node = $catalogue;

        foreach (explode('.', $namespace) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new RuntimeException("The portal's catalogue has no `{$namespace}` at all.");
            }

            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new RuntimeException("`{$namespace}` is a string, not a group of them.");
        }

        /** @var array<string, mixed> $node */
        return $node;
    }
}
