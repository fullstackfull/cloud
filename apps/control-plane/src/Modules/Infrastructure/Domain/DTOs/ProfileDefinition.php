<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\DTOs;

/**
 * A named set of components for a machine role, in install order, bound to
 * the playbook that builds that role.
 */
final readonly class ProfileDefinition
{
    /**
     * @param  list<string>  $components  Component keys, in order.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $intendedRole,
        public string $playbook,
        public array $components,
        public ?string $description = null,
    ) {}
}
