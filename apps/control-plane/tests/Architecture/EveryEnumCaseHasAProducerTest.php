<?php

declare(strict_types=1);

namespace Tests\Architecture;

use BackedEnum;
use Lynomia\Modules\Activity\Domain\Enums\ActivityCategory;
use Lynomia\Modules\Activity\Domain\Enums\ActorType;
use Lynomia\Modules\Activity\Domain\Enums\AttentionSeverity;
use Lynomia\Modules\Backups\Domain\Enums\BackupMode;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\DiscountType;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerCapability;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Domain\Enums\LegalDocumentType;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\FactSource;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuAllocationState;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuPassthroughMode;
use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingConcept;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\VerificationLevel;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Enums\PaymentMethodKind;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ReadinessAnswer;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Provisioning\Domain\Enums\CompensationAction;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use RuntimeException;
use Tests\Support\EnumCaseReferences;

/**
 * Every case of every enum declared under `src` is one some production code
 * produces — or it is named below, with how it arrives or why nothing does.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * {@see EveryStateAMachineCanEnterHasAProducerTest} asks whether anything
 * writes the states a machine can enter. That left two kinds of case with no
 * oracle at all: a case of an enum no machine governs
 * (`PaymentMethodKind::BankTransfer`, `ReleaseReason::Abuse`), and a case of
 * a machine's enum that no transition targets. A `case Scrapped` added to
 * `ProvisioningJobStatus`, with strings in both languages, left the whole
 * architecture and unit suites green. This gate goes red on it.
 *
 * ===========================================================================
 * WHAT IT READS
 * ===========================================================================
 *
 * **Subjects**: every case of every enum declared in a PHP file under `src`
 * ({@see EnumCaseReferences::enums()}), except the states a machine can enter,
 * which are the sibling gate's subject and are excused there.
 *
 * **Producers**: the sites {@see EnumCaseReferences} classifies as writing a
 * case. Its docblock is the exact list of what it reads and what it does not
 * see, and both are this gate's limits.
 *
 * ===========================================================================
 * THE EXCUSES, AND WHAT EACH IS HELD TO
 * ===========================================================================
 *
 * A case the classifier sees no producer for is either excused here or a
 * failure. There are four kinds of excuse, and each carries something the
 * second test checks, so that none outlives its reason:
 *
 *  - **`by value`**, for a whole enum: its cases cross into the platform as a
 *    value — a request field, an operator's form, the reference topology, a
 *    configuration key — and are built with `Enum::from()` or
 *    `Enum::tryFrom()`. The entry names the file where that happens, and the
 *    file must construct the enum (read by the classifier, not by a string
 *    search). This is a weaker claim than a producer: the gate cannot say
 *    which values arrive, so a case added to such an enum is not noticed —
 *    except where the site restricts the values with a literal `in:` rule
 *    over the enum's values ({@see casesTheSiteCannotAccept()} says exactly
 *    what it reads): then a case with no producer that the rule refuses must
 *    answer for itself in CASES, as `CustomerStatus::Closed` does beside
 *    `CustomerController`'s `in:active,suspended`. A
 *    `from()` in a list filter or over a stored column is a read, and does
 *    not qualify: the file named is the one where the value is chosen.
 *  - **`vocabulary`**, for a whole enum: its cases are names code asks about
 *    or walks — a permission, a capability, a naming rule — not values a row
 *    takes. The entry names a file and the spelling there that walks or asks,
 *    and the file's code (its comments left out) must still contain it.
 *  - **`spelled`**, for one case: it is produced, by a spelling the classifier
 *    reads as a read or cannot read at all — a string default, a scalar
 *    concatenated into SQL, a ternary branch. The entry names the file and the
 *    spelling, and the file's code (its comments left out) must still contain
 *    it. The search is for the text, so a read that spells the case the same
 *    way also satisfies it; the spelling is chosen to name the write.
 *  - **`unwritten`**, for one case: nothing produces it. The entry names the
 *    module that owns the decision — build the writer, delete the case, or
 *    declare it prepared. These, with the sibling gate's `UNPRODUCED`, are the
 *    states nothing can enter, and {@see EveryStateAScreenShowsIsTranslatedTest}
 *    demands no string for them.
 *
 * An enum a state machine governs cannot be excused `by value` or as a
 * `vocabulary`: its cases are the states rows are in, and each is answered
 * for one at a time.
 *
 * These entries were written by reading each site at the time; "nothing
 * writes it" means nothing under `src/` or `app/` names it in a writing
 * position, and a string search for its value found no writer either. That
 * search is not repeated by this gate. A case that gains a writer spelled as
 * a string stays excused as `unwritten`, so this gate stays green and the
 * translation gate stops demanding its strings for a state that now occurs.
 * A writer the classifier can see fails the excuse as stale.
 */
final class EveryEnumCaseHasAProducerTest extends TestCase
{
    /**
     * Enums whose cases arrive as a value or are a vocabulary.
     *
     * @var array<class-string, array{kind: 'by value'|'vocabulary', site: string, spelling?: string, why: string}>
     */
    public const array WHOLE_ENUMS = [
        BackupMode::class => ['kind' => 'by value', 'site' => 'src/Modules/Backups/Http/Requests/CreateBackupRequest.php', 'why' => 'The customer chooses the mode on the backup request.'],
        BillingPeriod::class => ['kind' => 'by value', 'site' => 'src/Modules/Catalog/Http/Controllers/OperatorCatalogueController.php', 'why' => 'An operator prices a plan for a period, and an order carries the period the customer picked.'],
        ProductKind::class => ['kind' => 'by value', 'site' => 'src/Modules/Catalog/Application/Actions/RecordProduct.php', 'why' => 'An operator records a product of a kind.'],
        ClusterStatus::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/InventoryController.php', 'why' => 'An operator sets a cluster\'s status.'],
        ComputeDriver::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/InventoryController.php', 'why' => 'An operator registers a cluster with its driver.'],
        CpuArchitecture::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/VmTemplateController.php', 'why' => 'An operator declares a template\'s architecture; a node reports its own.'],
        OsFamily::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/VmTemplateController.php', 'why' => 'An operator declares a template\'s OS family.'],
        SuspensionPolicy::class => ['kind' => 'by value', 'site' => 'src/Modules/Compute/Domain/Enums/SuspensionPolicy.php', 'why' => 'Read from config(\'compute.suspension_policy\'); unset means the strictest.'],
        DedicatedPowerAction::class => ['kind' => 'by value', 'site' => 'src/Modules/Dedicated/Http/Requests/PowerActionRequest.php', 'why' => 'The customer names the power action.'],
        ZoneImportMode::class => ['kind' => 'by value', 'site' => 'src/Modules/Dns/Http/Requests/ApplyZoneImportRequest.php', 'why' => 'The customer chooses merge or replace.'],
        CustomerRole::class => ['kind' => 'by value', 'site' => 'src/Modules/Identity/Http/Requests/InviteMemberRequest.php', 'why' => 'The account owner chooses a member\'s role when inviting or changing it.'],
        CustomerStatus::class => ['kind' => 'by value', 'site' => 'src/Modules/Admin/Http/Controllers/CustomerController.php', 'why' => 'An operator sets a customer\'s status.'],
        CustomerType::class => ['kind' => 'by value', 'site' => 'src/Modules/Identity/Application/Actions/RegisterCustomer.php', 'why' => 'The person registering chooses the account type.'],
        DeploymentKind::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/DeploymentJobController.php', 'why' => 'An operator requests an apply or a verify.'],
        GpuPassthroughMode::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/ServerController.php', 'why' => 'An operator registers a card with its passthrough mode.'],
        PreflightMode::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/PreflightController.php', 'why' => 'An operator chooses the preflight mode (and the console command takes it as an option).'],
        ReferenceKind::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Domain/Reference/ReferenceTopology.php', 'why' => 'Each object in the reference topology document names its kind.'],
        IpPoolScope::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/InventoryController.php', 'why' => 'An operator creates a pool with its scope.'],
        NetworkPurpose::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/InventoryController.php', 'why' => 'An operator creates a network with its purpose.'],
        Product::class => ['kind' => 'by value', 'site' => 'src/Modules/ProductReadiness/Http/Controllers/ProductReadinessController.php', 'why' => 'An operator names the product whose readiness is read or declared.'],
        DriftStatus::class => ['kind' => 'by value', 'site' => 'src/Modules/Admin/Http/Controllers/DriftController.php', 'why' => 'An operator gives the verdict on a drift.'],
        HostingNodeStatus::class => ['kind' => 'by value', 'site' => 'src/Modules/Infrastructure/Http/Controllers/InventoryController.php', 'why' => 'An operator sets a hosting node\'s status.'],
        WordPressDomainSource::class => ['kind' => 'by value', 'site' => 'src/Modules/SharedHosting/Http/Controllers/WordPressController.php', 'why' => 'The customer says where the site\'s domain comes from.'],
        WordPressPushScope::class => ['kind' => 'by value', 'site' => 'src/Modules/SharedHosting/Http/Requests/PushWordPressToProductionRequest.php', 'why' => 'The customer chooses what a push overwrites.'],
        DeploymentEnvironment::class => ['kind' => 'by value', 'site' => 'src/Modules/Providers/Http/Controllers/CredentialController.php', 'why' => 'An operator records a credential, licence or provider for an environment.'],
        TicketCategory::class => ['kind' => 'by value', 'site' => 'src/Modules/Support/Http/Requests/OpenTicketRequest.php', 'why' => 'The customer chooses the category of a ticket.'],
        TicketPriority::class => ['kind' => 'by value', 'site' => 'src/Modules/Support/Http/Controllers/OperatorTicketController.php', 'why' => 'An operator sets a ticket\'s priority, any of the four; a customer chooses from the lower three when opening one.'],

        CustomerCapability::class => ['kind' => 'vocabulary', 'site' => 'src/Modules/Identity/Http/Resources/TeamRoleResource.php', 'spelling' => 'CustomerCapability::cases()', 'why' => 'A capability is a question CustomerRole answers, not a value a row takes; the team permission matrix walks every case.'],
        LegalDocumentType::class => ['kind' => 'vocabulary', 'site' => 'src/Modules/Identity/Domain/Services/LegalDocuments.php', 'spelling' => 'LegalDocumentType::cases()', 'why' => 'Every document type is required: registration walks the cases and records an acceptance of each.'],
        NamingConcept::class => ['kind' => 'vocabulary', 'site' => 'src/Modules/Infrastructure/Application/Naming/AuditInfrastructureNaming.php', 'spelling' => 'NamingConcept::persisted()', 'why' => 'A naming rule is applied, not stored: the naming audit walks every persisted concept.'],
        ControlledDriver::class => ['kind' => 'vocabulary', 'site' => 'src/Modules/Providers/Infrastructure/ProvidersServiceProvider.php', 'spelling' => 'ControlledDriver::cases()', 'why' => 'A controlled driver is a name the registry binds a tester to, for every case.'],
        Permission::class => ['kind' => 'vocabulary', 'site' => 'src/Modules/Rbac/Http/Requests/SetRolePermissionsRequest.php', 'spelling' => 'Permission::cases()', 'why' => 'A permission is a name a gate asks about; an operator may grant any case to a role, and Super Admin holds all of them.'],
    ];

    /**
     * Single cases: produced by a spelling the classifier does not read, or
     * produced by nothing.
     *
     * @var array<string, array{kind: 'spelled', site: string, spelling: string, why: string}|array{kind: 'unwritten', owner: string, why: string}>
     */
    public const array CASES = [
        ActivityCategory::class.'::Domains' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActivityCategory::Domains->value', 'why' => 'The activity feed selects it as an SQL literal, DB::raw("\'".X->value."\' as category").'],
        ActivityCategory::class.'::Billing' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActivityCategory::Billing->value', 'why' => 'Selected as an SQL literal by the activity feed.'],
        ActivityCategory::class.'::Support' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActivityCategory::Support->value', 'why' => 'Selected as an SQL literal by the activity feed.'],
        ActivityCategory::class.'::Backups' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActivityCategory::Backups->value', 'why' => 'Selected as an SQL literal by the activity feed.'],
        ActorType::class.'::CustomerUser' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActorType::CustomerUser->value', 'why' => 'Selected as an SQL literal by the activity feed.'],
        ActorType::class.'::System' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActorType::System->value', 'why' => 'Selected as an SQL literal by the activity feed.'],
        ActorType::class.'::Unknown' => ['kind' => 'spelled', 'site' => 'src/Modules/Activity/Application/Queries/ActivitySources.php', 'spelling' => 'ActorType::Unknown->value', 'why' => 'Selected as an SQL literal by the activity feed.'],
        GpuAllocationState::class.'::Available' => ['kind' => 'spelled', 'site' => 'database/migrations/2026_04_05_000000_create_gpu_devices.php', 'spelling' => "->default('available')", 'why' => 'A registered card takes the column default; RegisterGpuDevice does not name a state.'],
        VerificationLevel::class.'::CodeComplete' => ['kind' => 'spelled', 'site' => 'src/Modules/Infrastructure/Domain/Preflight/PreflightReport.php', 'spelling' => '[VerificationLevel::CodeComplete->value, VerificationLevel::Tested->value]', 'why' => 'Every report lists it, as a scalar in a list literal.'],
        VerificationLevel::class.'::Tested' => ['kind' => 'spelled', 'site' => 'src/Modules/Infrastructure/Domain/Preflight/PreflightReport.php', 'spelling' => '[VerificationLevel::CodeComplete->value, VerificationLevel::Tested->value]', 'why' => 'Every report lists it, as a scalar in a list literal.'],
        ReadinessAnswer::class.'::NotApplicable' => ['kind' => 'spelled', 'site' => 'src/Modules/ProductReadiness/Domain/Services/ReadinessQuestions.php', 'spelling' => '? ReadinessAnswer::NotApplicable->value', 'why' => 'Written as the scalar in a ternary branch under an array key.'],
        LicenceState::class.'::Unknown' => ['kind' => 'spelled', 'site' => 'src/Modules/Providers/Infrastructure/Models/Licence.php', 'spelling' => "'state' => 'unknown'", 'why' => 'A new licence takes the model\'s string default.'],
        WordPressSiteKind::class.'::Production' => ['kind' => 'spelled', 'site' => 'src/Modules/SharedHosting/Infrastructure/Models/WordPressSite.php', 'spelling' => "'kind' => 'production'", 'why' => 'A new site takes the model\'s string default; copies name staging or clone.'],

        AttentionSeverity::class.'::Info' => ['kind' => 'unwritten', 'owner' => 'Activity', 'why' => 'AccountAttention assigns only Critical and Warning; nothing on the dashboard is informational yet.'],
        BackupTrigger::class.'::Scheduled' => ['kind' => 'unwritten', 'owner' => 'Backups', 'why' => 'Every backup is requested by a person (BackupController, and RequestServiceBackup\'s default Manual); no scheduler creates one.'],
        InvoiceItemKind::class.'::Addon' => ['kind' => 'unwritten', 'owner' => 'Billing', 'why' => 'Invoices carry plan, credit and proration lines; nothing bills an add-on.'],
        InvoiceItemKind::class.'::Usage' => ['kind' => 'unwritten', 'owner' => 'Billing', 'why' => 'Nothing bills metered usage.'],
        InvoiceItemKind::class.'::Setup' => ['kind' => 'unwritten', 'owner' => 'Billing', 'why' => 'A plan\'s setup fee is folded into its plan line (PricingLine::total); nothing writes a setup line.'],
        DiscountType::class.'::Percentage' => ['kind' => 'unwritten', 'owner' => 'Catalog', 'why' => 'No production code creates a coupon; coupons are only read (OrderPricing, CouponTermsRepository), and only tests and factories write one.'],
        DiscountType::class.'::FixedAmount' => ['kind' => 'unwritten', 'owner' => 'Catalog', 'why' => 'No production code creates a coupon.'],
        ComponentKind::class.'::Gpu' => ['kind' => 'unwritten', 'owner' => 'Dedicated', 'why' => 'No BMC adapter reports a GPU component.'],
        InstallerKind::class.'::Autoinstall' => ['kind' => 'unwritten', 'owner' => 'Dedicated', 'why' => 'No production code writes an os_install_profiles row; the renderer reads the installer from rows only tests create.'],
        InstallerKind::class.'::Preseed' => ['kind' => 'unwritten', 'owner' => 'Dedicated', 'why' => 'No production code writes an os_install_profiles row.'],
        InstallerKind::class.'::Kickstart' => ['kind' => 'unwritten', 'owner' => 'Dedicated', 'why' => 'No production code writes an os_install_profiles row.'],
        RegistrarCapability::class.'::Renewal' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'No registrar adapter declares it and nothing asks for it.'],
        RegistrarCapability::class.'::PremiumPricing' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'No registrar adapter declares it; DomainPricing refuses premium names by the string premium_pricing.'],
        RegistrarCapability::class.'::MultiYearTerms' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'No registrar adapter declares it and nothing asks for it.'],
        DomainContactRole::class.'::Registrant' => ['kind' => 'spelled', 'site' => 'src/Modules/Domains/Http/Controllers/DomainController.php', 'spelling' => '[DomainContactRole::Registrant->value =>', 'why' => 'Ordering a domain and updating its contacts both key the one contact the customer gives by this role.'],
        DomainContactRole::class.'::Administrative' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'The order and contact-update requests accept a registrant only, and DomainController passes only that role to OrderDomainRegistration and UpdateDomainContacts, the only writers of domain contacts.'],
        DomainContactRole::class.'::Technical' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'The order and contact-update requests accept a registrant only; nothing writes a technical contact.'],
        DomainContactRole::class.'::Billing' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'The order and contact-update requests accept a registrant only; nothing writes a billing contact.'],
        Role::class.'::InfrastructureAdmin' => ['kind' => 'spelled', 'site' => 'src/Modules/Rbac/Http/Requests/ChangeOperatorRolesRequest.php', 'spelling' => 'Role::cases()', 'why' => 'Assigned by name: assignable() walks every staff case into the roles an operator may be invited with or given, and syncRoles writes the name (an infrastructure admin).'],
        Role::class.'::BillingAdmin' => ['kind' => 'spelled', 'site' => 'src/Modules/Rbac/Http/Requests/ChangeOperatorRolesRequest.php', 'spelling' => 'Role::cases()', 'why' => 'Assigned by name: assignable() walks every staff case into the roles an operator may be invited with or given, and syncRoles writes the name (a billing admin).'],
        Role::class.'::Support' => ['kind' => 'spelled', 'site' => 'src/Modules/Rbac/Http/Requests/ChangeOperatorRolesRequest.php', 'spelling' => 'Role::cases()', 'why' => 'Assigned by name: assignable() walks every staff case into the roles an operator may be invited with or given, and syncRoles writes the name (support).'],
        Role::class.'::NetworkEngineer' => ['kind' => 'spelled', 'site' => 'src/Modules/Rbac/Http/Requests/ChangeOperatorRolesRequest.php', 'spelling' => 'Role::cases()', 'why' => 'Assigned by name: assignable() walks every staff case into the roles an operator may be invited with or given, and syncRoles writes the name (a network engineer).'],
        Role::class.'::Noc' => ['kind' => 'spelled', 'site' => 'src/Modules/Rbac/Http/Requests/ChangeOperatorRolesRequest.php', 'spelling' => 'Role::cases()', 'why' => 'Assigned by name: assignable() walks every staff case into the roles an operator may be invited with or given, and syncRoles writes the name (the NOC).'],
        Role::class.'::Finance' => ['kind' => 'spelled', 'site' => 'src/Modules/Rbac/Http/Requests/ChangeOperatorRolesRequest.php', 'spelling' => 'Role::cases()', 'why' => 'Assigned by name: assignable() walks every staff case into the roles an operator may be invited with or given, and syncRoles writes the name (finance).'],
        Role::class.'::Customer' => ['kind' => 'spelled', 'site' => 'src/Modules/Identity/Application/Actions/RegisterCustomer.php', 'spelling' => 'assignRole(Role::Customer->value)', 'why' => 'Registration assigns the customer role by its name.'],
        NodeStatus::class.'::Active' => ['kind' => 'spelled', 'site' => 'src/Modules/Infrastructure/Application/Actions/ChangeComputeNodeStatus.php', 'spelling' => 'NodeStatus::Active', 'why' => 'An operator puts a node into service: ComputeNodeController builds the status by value from the SETTABLE list this names.'],
        NodeStatus::class.'::Draining' => ['kind' => 'spelled', 'site' => 'src/Modules/Infrastructure/Application/Actions/ChangeComputeNodeStatus.php', 'spelling' => 'NodeStatus::Draining', 'why' => 'An operator drains a node: built by value from the same SETTABLE list.'],
        NodeStatus::class.'::Offline' => ['kind' => 'unwritten', 'owner' => 'Compute', 'why' => 'ChangeComputeNodeStatus refuses offline and the reconcile sweep records a silent node as unhealthy, not offline; only the simulation-only reference topology loader can write it.'],
        ServerState::class.'::Connected' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'The deployment path writes registered, profiled and managed; nothing writes connected outside the simulation-only reference topology loader.'],
        ServerState::class.'::Discovered' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'RegisterServer writes registered, AssignDesiredState profiled and RunDeploymentJob managed; nothing discovers a server into this state (AssignDesiredState only reads it) outside the simulation-only reference topology loader.'],
        ServerState::class.'::Retired' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'Nothing retires a managed server; only the simulation-only reference topology loader could write it.'],
        DomainState::class.'::TransferredAway' => ['kind' => 'unwritten', 'owner' => 'Domains', 'why' => 'Nothing detects a transfer away: no registrar adapter, reconciliation or action reports that a name left for another registrar, so no row is written transferred_away. DomainState::canBecome() only permits it, and holdsTheName() and the domains_one_live_holder index only read it.'],
        CountryCurrencyChangeState::class.'::Requested' => ['kind' => 'unwritten', 'owner' => 'Identity', 'why' => 'RequestCountryCurrencyChange writes blocked or awaiting_approval; nothing writes requested.'],
        LoginOutcome::class.'::UnverifiedEmail' => ['kind' => 'unwritten', 'owner' => 'Identity', 'why' => 'No sign-in path records an unverified-email outcome.'],
        LoginOutcome::class.'::TokenIssued' => ['kind' => 'unwritten', 'owner' => 'Identity', 'why' => 'No sign-in path records a token issue.'],
        CustomerStatus::class.'::Closed' => ['kind' => 'unwritten', 'owner' => 'Identity', 'why' => 'Nothing closes an account: the operator\'s status change (CustomerController::setStatus) validates in:active,suspended, and no other path writes closed.'],
        DeploymentState::class.'::Requested' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'RequestDeployment writes queued; CancelDeployment and the one-open-deployment index read this state, nothing writes it.'],
        DeploymentState::class.'::Preflight' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'The runner never records a preflight step as a state.'],
        DeploymentState::class.'::Planning' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'The runner never records a planning step as a state.'],
        DeploymentState::class.'::AwaitingApproval' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'Plans are approved on their own row; no deployment is put to wait for it.'],
        FactSource::class.'::Declared' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'Nothing declares a fact: DiscoverServer writes discovered and RunDeploymentJob derived; no form or action records a person\'s note as a server fact, so FactSource::mayReplace() only compares against it.'],
        GpuAllocationState::class.'::Allocated' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'Nothing allocates a GPU card yet.'],
        GpuAllocationState::class.'::Reserved' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'Nothing reserves a GPU card yet.'],
        GpuAllocationState::class.'::Faulted' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'Nothing marks a GPU card faulted yet.'],
        PlanRisk::class.'::Destructive' => ['kind' => 'unwritten', 'owner' => 'Infrastructure', 'why' => 'No component in SoftwareCatalogue declares destructive risk, so no plan is destructive and PlanEngine\'s destructive branch is never taken.'],
        ReleaseReason::class.'::CustomerRequest' => ['kind' => 'unwritten', 'owner' => 'Ipam', 'why' => 'No release path passes it (F-34\'s open half).'],
        ReleaseReason::class.'::Abuse' => ['kind' => 'unwritten', 'owner' => 'Ipam', 'why' => 'No release path passes it, so the quarantine rule\'s abuse branch is unreachable (F-34\'s open half).'],
        ReleaseReason::class.'::Migration' => ['kind' => 'unwritten', 'owner' => 'Ipam', 'why' => 'No release path passes it (F-34\'s open half).'],
        NotificationChannel::class.'::Sms' => ['kind' => 'unwritten', 'owner' => 'Notifications', 'why' => 'Declared and not implemented: preferences accept only implemented channels and NotifyCustomer skips the rest.'],
        NotificationChannel::class.'::WhatsApp' => ['kind' => 'unwritten', 'owner' => 'Notifications', 'why' => 'Declared and not implemented.'],
        NotificationChannel::class.'::Push' => ['kind' => 'unwritten', 'owner' => 'Notifications', 'why' => 'Declared and not implemented.'],
        PaymentAttemptStatus::class.'::Succeeded' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'A capture is recorded as a transaction; nothing moves the attempt to succeeded.'],
        PaymentMethodKind::class.'::Card' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'No production code stores a payment method: the model and its cast exist and nothing writes a row.'],
        PaymentMethodKind::class.'::BankTransfer' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'No production code stores a payment method.'],
        PaymentMethodKind::class.'::Knet' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'No production code stores a payment method.'],
        PaymentMethodKind::class.'::Wallet' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'No production code stores a payment method; a wallet payment is recorded with the provider string "wallet".'],
        TransactionKind::class.'::Refund' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'Only charges are written as transactions; IssueRefund credits the wallet.'],
        TransactionKind::class.'::Chargeback' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'Nothing records a chargeback.'],
        TransactionKind::class.'::Adjustment' => ['kind' => 'unwritten', 'owner' => 'Payments', 'why' => 'Nothing records an adjustment transaction.'],
        CapabilityState::class.'::BlockedConfiguration' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'No tester or assessment records a capability as blocked by configuration.'],
        ConnectionState::class.'::Testing' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'A connection test records its result, never a test in progress.'],
        CredentialState::class.'::Untested' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'A recorded credential is configured or missing until a test says valid or invalid.'],
        CredentialState::class.'::Expired' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'Nothing expires a credential reference.'],
        CredentialState::class.'::RotationDue' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'Nothing marks a credential due for rotation.'],
        LicenceState::class.'::NotRequired' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'Nothing records a licence as not required, though the preflight and RefreshLicenceStates read it.'],
        ReadinessState::class.'::ReadyForDiscovery' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'ProviderReadiness reaches not_ready, ready_for_test and ready_for_production only.'],
        ReadinessState::class.'::ReadyForConfiguration' => ['kind' => 'unwritten', 'owner' => 'Providers', 'why' => 'ProviderReadiness reaches not_ready, ready_for_test and ready_for_production only.'],
        CompensationAction::class.'::NothingToDo' => ['kind' => 'unwritten', 'owner' => 'Provisioning', 'why' => 'CompensateFailedJob answers quarantined or released.'],
        PlacementRejectionReason::class.'::NodeUnhealthy' => ['kind' => 'unwritten', 'owner' => 'SharedHosting', 'why' => 'HostingNodeScheduler never rejects a node as unhealthy.'],
        SslStatus::class.'::None' => ['kind' => 'unwritten', 'owner' => 'SharedHosting', 'why' => 'Sites and accounts are written unknown, pending or active; no adapter reports none.'],
        SslStatus::class.'::Expired' => ['kind' => 'unwritten', 'owner' => 'SharedHosting', 'why' => 'No adapter reports an expired certificate.'],
        SslStatus::class.'::Failed' => ['kind' => 'unwritten', 'owner' => 'SharedHosting', 'why' => 'No adapter reports a failed certificate.'],
        WordPressSiteState::class.'::Removed' => ['kind' => 'unwritten', 'owner' => 'SharedHosting', 'why' => 'Nothing removes a WordPress site; two duplicate-domain checks exclude the state.'],
        MessageAuthorKind::class.'::System' => ['kind' => 'unwritten', 'owner' => 'Support', 'why' => 'Every ticket message is written by a customer or an operator.'],
        WalletTransactionKind::class.'::Promotional' => ['kind' => 'unwritten', 'owner' => 'Wallet', 'why' => 'Nothing grants promotional credit.'],
    ];

    #[Test]
    public function every_case_of_every_enum_is_produced_or_excused(): void
    {
        $unexcused = [];

        foreach (self::subjects() as $case => $producers) {
            if ($producers !== [] || isset(self::CASES[$case]) || isset(self::WHOLE_ENUMS[self::enumOf($case)])) {
                continue;
            }

            $unexcused[] = $case;
        }

        $this->assertSame([], $unexcused, sprintf(
            "These enum cases have no producer in src/ or app/ and no excuse:\n  %s\n\n".
            'Give the case a writer, delete it, or add it to CASES or WHOLE_ENUMS with the kind of excuse this '.
            'docblock describes — and, for a case nothing writes, the module that owns the decision.',
            implode("\n  ", $unexcused),
        ));
    }

    #[Test]
    public function no_excuse_outlives_what_it_excuses(): void
    {
        $subjects = self::subjects();
        $governed = EnumCaseReferences::governedEnums();
        $constructions = EnumCaseReferences::constructions();
        $stale = [];

        foreach (self::CASES as $case => $excuse) {
            if (! array_key_exists($case, $subjects)) {
                $stale[] = "{$case} — no longer a case, or now a state a machine can enter (excuse it there)";

                continue;
            }

            foreach ($subjects[$case] as [$file, $line, $position]) {
                $stale[] = "{$case} — produced at {$file}:{$line} ({$position})";
            }

            if ($excuse['kind'] === 'spelled' && ! self::fileSays($excuse['site'], $excuse['spelling'])) {
                $stale[] = "{$case} — {$excuse['site']} no longer contains {$excuse['spelling']}";
            }
        }

        foreach (self::WHOLE_ENUMS as $enum => $excuse) {
            $cases = array_filter($subjects, static fn (string $case): bool => self::enumOf($case) === $enum, ARRAY_FILTER_USE_KEY);

            if ($cases === []) {
                $stale[] = "{$enum} — not an enum under src any more";

                continue;
            }

            if (array_filter($cases, static fn (array $producers): bool => $producers === []) === []) {
                $stale[] = "{$enum} — every case now has a producer";
            }

            // A case answered for in CASES beside a whole-enum excuse is one
            // the excuse's own site refuses by a literal `in:` rule (as
            // CustomerStatus::Closed beside the operator's active/suspended
            // choice). Any other pairing means the enum's cases are being
            // answered for one at a time, and the whole-enum excuse would
            // hide whichever of them nobody named.
            $accepted = $excuse['kind'] === 'by value' ? self::valuesTheSiteAccepts($enum, $excuse['site']) : null;

            foreach (array_keys(self::CASES) as $answered) {
                $value = self::enumOf($answered) === $enum ? constant($answered) : null;

                if ($value !== null && ($accepted === null || ! $value instanceof BackedEnum || in_array((string) $value->value, $accepted, true))) {
                    $stale[] = "{$answered} — answered for in CASES beside {$enum}'s whole-enum excuse, whose site does not refuse it; answer for every case of the enum in CASES instead";
                }
            }

            if (isset($governed[$enum])) {
                $stale[] = "{$enum} — governed by a state machine, so its cases are answered for one at a time";
            }

            if ($excuse['kind'] === 'by value') {
                $sites = array_column($constructions[$enum] ?? [], 0);

                if (! in_array($excuse['site'], $sites, true)) {
                    $stale[] = "{$enum} — {$excuse['site']} does not build it with from() or tryFrom() (it is built in: ".implode(', ', array_unique($sites)).')';
                }

                foreach (self::casesTheSiteCannotAccept($enum, $excuse['site'], $cases) as $case => $rules) {
                    $stale[] = "{$case} — the by-value excuse names {$excuse['site']}, whose ".implode(' and ', $rules).' does not accept '.constant($case)->value.'; nothing produces it there, so answer for it in CASES';
                }
            } elseif (! self::fileSays($excuse['site'], $excuse['spelling'] ?? '')) {
                $stale[] = "{$enum} — {$excuse['site']} no longer contains ".($excuse['spelling'] ?? '(no spelling given)');
            }
        }

        $this->assertSame([], $stale, sprintf(
            "These excuses no longer hold:\n  %s\n\nRemove or correct the entry.",
            implode("\n  ", $stale),
        ));
    }

    /**
     * The states nothing can enter, from both gates: this one's `unwritten`
     * cases and the machine gate's `UNPRODUCED` states. The translation gate
     * reads this, so the two cannot disagree about a state.
     *
     * @return list<string> "Enum::Case"
     */
    public static function unenterable(): array
    {
        $cases = array_keys(array_filter(self::CASES, static fn (array $excuse): bool => $excuse['kind'] === 'unwritten'));

        /** @var array<string, string> $unproduced */
        $unproduced = (new ReflectionClassConstant(EveryStateAMachineCanEnterHasAProducerTest::class, 'UNPRODUCED'))->getValue();

        return [...$cases, ...array_keys($unproduced)];
    }

    /**
     * The machine gate's list is private to it and read here by reflection;
     * this pins that both lists reach {@see unenterable()} and that a
     * produced-but-unseen case does not.
     */
    #[Test]
    public function the_states_nothing_can_enter_are_both_gates_admissions(): void
    {
        $unenterable = self::unenterable();

        $this->assertContains('Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus::Uncollectible', $unenterable);
        $this->assertContains('Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus::Cancelled', $unenterable);
        $this->assertContains(PaymentMethodKind::class.'::BankTransfer', $unenterable);
        $this->assertNotContains(ActorType::class.'::System', $unenterable, 'A spelled case is produced; it is not a state nothing can enter.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tablesAndConditions(): iterable
    {
        $enum = static fn (string $methods): string => "<?php\nnamespace Tests\\Architecture;\nenum Fixture: string\n{\n    case A = 'a';\n    case B = 'b';\n{$methods}\n}\n";
        $probe = static fn (string $body): string => "<?php\nnamespace App\\Probe;\nuse Tests\\Architecture\\Fixture;\nfinal class Probe\n{\n    public function run(mixed \$a, mixed \$b): mixed\n    {\n        return {$body};\n    }\n}\n";

        yield 'a member of a match arm\'s condition list' => [$probe('match ([$a, $b]) { [Fixture::A, Fixture::B] => true, default => false }'), 'match arm condition'];
        yield 'a member of a nested condition list' => [$probe('match ([$a, [$b]]) { [Fixture::A, [Fixture::B]] => true, default => false }'), 'match arm condition'];
        yield 'a member of a match arm\'s result list' => [$probe('match ($a) { Fixture::A => [Fixture::B], default => [] }'), 'producer'];
        yield 'a call inside a condition list' => [$probe('match ([$a]) { [wrap(Fixture::B)] => true, default => false }'), 'producer'];

        $table = 'return in_array($next, match ($this) { self::A => [self::B], default => [] }, true);';

        yield 'an enum\'s own canBecome table' => [$enum("    public function canBecome(self \$next): bool\n    {\n        {$table}\n    }"), 'enum transition table'];
        yield 'a private table only canBecome reads' => [$enum("    public function canBecome(self \$next): bool\n    {\n        return in_array(\$next, \$this->allowed(), true);\n    }\n    private function allowed(): array\n    {\n        return match (\$this) { self::A => [self::B], default => [] };\n    }"), 'enum transition table'];
        yield 'a public table canBecome reads' => [$enum("    public function canBecome(self \$next): bool\n    {\n        return in_array(\$next, \$this->allowed(), true);\n    }\n    public function allowed(): array\n    {\n        return match (\$this) { self::A => [self::B], default => [] };\n    }"), 'producer'];
        yield 'a private table something else also reads' => [$enum("    public function canBecome(self \$next): bool\n    {\n        return in_array(\$next, \$this->allowed(), true);\n    }\n    public function first(): self\n    {\n        return \$this->allowed()[0];\n    }\n    private function allowed(): array\n    {\n        return match (\$this) { self::A => [self::B], default => [] };\n    }"), 'producer'];
        yield 'a canBecome that is not declared bool' => [$enum("    public function canBecome(self \$next): ?bool\n    {\n        {$table}\n    }"), 'producer'];
        yield 'a canBecome declared in a class' => ["<?php\nnamespace App\\Probe;\nuse Tests\\Architecture\\Fixture;\nfinal class Probe\n{\n    public function canBecome(Fixture \$next): bool\n    {\n        return in_array(\$next, match (\$next) { Fixture::A => [Fixture::B], default => [] }, true);\n    }\n}\n", 'producer'];
        yield 'an enum method that is not the table' => [$enum("    public function canBecome(self \$next): bool\n    {\n        return true;\n    }\n    public function next(): self\n    {\n        return self::B;\n    }"), 'producer'];
    }

    /**
     * The two rules that stopped a case's own enum from standing as its
     * producer — `FactSource::Declared` in `mayReplace()`'s condition lists,
     * `DomainState::TransferredAway` in `canBecome()`'s table — and the lines
     * each must not cross, on sources written here.
     */
    #[Test]
    #[DataProvider('tablesAndConditions')]
    public function the_classifier_reads_a_match_condition_list_and_an_enums_own_transition_table_as_reads_and_nothing_else(string $source, string $expected): void
    {
        [$found] = EnumCaseReferences::classifySource($source, ['Tests\\Architecture\\Fixture' => ['B' => true]]);

        $this->assertNotSame([], $found, 'The probe names Fixture::B; the classifier must find it.');
        $this->assertSame([$expected], array_values(array_unique(array_column($found, 2))));
    }

    /**
     * The literal `in:` rule reading behind the by-value check, on the one
     * site it holds today and on the line it must not cross.
     */
    #[Test]
    public function a_by_value_site_is_held_to_the_values_its_literal_in_rule_accepts(): void
    {
        $customerStatus = [CustomerStatus::class.'::Active' => [], CustomerStatus::class.'::Suspended' => [], CustomerStatus::class.'::Closed' => []];

        $this->assertSame(
            [],
            self::casesTheSiteCannotAccept(CustomerStatus::class, 'src/Modules/Admin/Http/Controllers/CustomerController.php', $customerStatus),
            'active and suspended are in the controller\'s in:active,suspended, and closed answers for itself in CASES.',
        );
        $this->assertSame('unwritten', self::CASES[CustomerStatus::class.'::Closed']['kind']);

        $this->assertSame(
            [DriftStatus::class.'::Open' => ["'in:acknowledged,resolved'"]],
            self::casesTheSiteCannotAccept(DriftStatus::class, 'src/Modules/Admin/Http/Controllers/DriftController.php', [DriftStatus::class.'::Open' => []]),
        );

        $this->assertSame(
            [],
            self::casesTheSiteCannotAccept(DriftStatus::class, 'src/Modules/Admin/Http/Controllers/DriftController.php', [DriftStatus::class.'::Open' => [['src/x.php', 1, 'producer']]]),
            'A case with a producer is not held to the site.',
        );

        $this->assertSame(
            [],
            self::casesTheSiteCannotAccept(BillingPeriod::class, 'src/Modules/Catalog/Http/Controllers/OperatorCatalogueController.php', [BillingPeriod::class.'::Hourly' => []]),
            'A site with no literal in: rule over the enum is not held to anything.',
        );
    }

    /**
     * Every subject case with its producer sites. The states a machine can
     * enter are left out: the sibling gate answers for them.
     *
     * @return array<string, list<array{string, int, string}>>
     */
    private static function subjects(): array
    {
        $destinations = EveryStateAMachineCanEnterHasAProducerTest::destinations();
        $cases = [];

        foreach (EnumCaseReferences::enums() as $enum => $names) {
            foreach ($names as $name) {
                if (! isset($destinations[$enum.'::'.$name])) {
                    $cases[] = $enum.'::'.$name;
                }
            }
        }

        $subjects = EnumCaseReferences::producersOf($cases);

        // An empty sweep would pass on nothing.
        if (count(EnumCaseReferences::enums()) < 100 || count($subjects) < 800) {
            throw new RuntimeException(sprintf('Only %d enums and %d cases were found under src; discovery is broken.', count(EnumCaseReferences::enums()), count($subjects)));
        }

        if (count(array_filter($subjects, static fn (array $sites): bool => $sites !== [])) < count($subjects) / 2) {
            throw new RuntimeException('Fewer than half of all enum cases have a producer; the classifier is broken rather than the tree.');
        }

        return $subjects;
    }

    /**
     * The cases a by-value excuse covers that its site's literal `in:` rules
     * refuse, each with the rules that refuse it.
     *
     * What is read: every single- or double-quoted string literal in the site
     * file (tokenised, so comments are not read), split on `|`; each segment
     * beginning `in:` is a rule, its values read as Laravel reads them
     * (`str_getcsv`). A rule restricts this enum when every value it lists is
     * one of the enum's values. When at least one does, a case with no
     * producer and no entry of its own in CASES must be listed by one of
     * them: the site cannot build a value its validation refuses. A site with
     * no such rule is not held to anything here. Not read: `Rule::in(...)`,
     * `Rule::enum(...)`, `new In(...)`, an `in:` rule built by concatenation
     * or held in a heredoc, and which request field a rule belongs to.
     *
     * @param  array<string, list<array{string, int, string}>>  $cases  "Enum::Case" → producers
     * @return array<string, list<string>> "Enum::Case" → the refusing rules, quoted
     */
    public static function casesTheSiteCannotAccept(string $enum, string $site, array $cases): array
    {
        $restricting = self::restrictingRules($enum, $site);

        if ($restricting === []) {
            return [];
        }

        $accepted = array_merge(...array_values($restricting));
        $refused = [];

        foreach ($cases as $case => $producers) {
            $value = constant($case);

            if ($producers !== [] || isset(self::CASES[$case]) || ! $value instanceof BackedEnum) {
                continue;
            }

            if (! in_array((string) $value->value, $accepted, true)) {
                $refused[$case] = array_keys($restricting);
            }
        }

        return $refused;
    }

    /**
     * The values a by-value site's literal `in:` rules accept, or null when it
     * has none (then it is read as accepting every case).
     *
     * @param  class-string  $enum
     * @return list<string>|null
     */
    private static function valuesTheSiteAccepts(string $enum, string $site): ?array
    {
        $restricting = self::restrictingRules($enum, $site);

        return $restricting === [] ? null : array_values(array_merge(...array_values($restricting)));
    }

    /**
     * The literal `in:` rules in the site whose every value is a value of the enum.
     *
     * @param  class-string  $enum
     * @return array<string, list<string>>
     */
    private static function restrictingRules(string $enum, string $site): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, is_subclass_of($enum, BackedEnum::class) ? $enum::cases() : []);
        $path = EnumCaseReferences::ROOT.'/'.$site;
        $restricting = [];

        foreach (is_file($path) ? PhpToken::tokenize((string) file_get_contents($path)) : [] as $token) {
            if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }

            foreach (explode('|', substr($token->text, 1, -1)) as $segment) {
                if (! str_starts_with($segment, 'in:')) {
                    continue;
                }

                $listed = array_map('strval', str_getcsv(substr($segment, 3), ',', '"', ''));

                if ($listed !== [] && array_diff($listed, $values) === []) {
                    $restricting["'{$segment}'"] = $listed;
                }
            }
        }

        return $restricting;
    }

    private static function enumOf(string $case): string
    {
        return substr($case, 0, (int) strrpos($case, '::'));
    }

    private static function fileSays(string $relative, string $spelling): bool
    {
        $path = EnumCaseReferences::ROOT.'/'.$relative;

        if ($spelling === '' || ! is_file($path)) {
            return false;
        }

        // Comments are left out: a spelling that survives only in a comment
        // is not the file producing the case.
        $code = '';

        foreach (PhpToken::tokenize((string) file_get_contents($path)) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return str_contains($code, $spelling);
    }
}
