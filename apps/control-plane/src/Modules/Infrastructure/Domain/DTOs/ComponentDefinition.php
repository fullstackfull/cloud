<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\DTOs;

use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;

/**
 * One installable thing, bound to the reviewed Ansible role that installs it.
 */
final readonly class ComponentDefinition
{
    /**
     * @param  list<string>  $dependsOn  Component keys that must be in the same profile, earlier.
     * @param  list<string>  $accepts  Override keys an operator may set for this component. Anything else is refused.
     * @param  string|null  $verification  How presence is confirmed afterwards: `service:<unit>`, `port:<n>` or null for "the role's own asserts".
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $category,
        public string $ansibleRole,
        public PlanRisk $risk,
        public bool $requiresReboot = false,
        public bool $requiresLicence = false,
        public ?string $licenceProduct = null,
        public ?string $verification = null,
        public array $dependsOn = [],
        public array $accepts = [],
        public ?string $description = null,
    ) {}
}
