<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\DTOs;

/**
 * One thing a connection test tried, and what happened.
 *
 * The steps are what makes a test useful rather than merely conclusive.
 * "auth_failed" tells an operator to check a credential. "tcp ok, tls ok,
 * auth ok, permissions insufficient" tells them the credential is right and
 * the role is wrong, which is a different afternoon entirely.
 *
 * `detail` is written for a person and is sanitised at the point of
 * construction. A step records that authentication was rejected; it never
 * records what was sent.
 */
final readonly class ConnectionStep
{
    private function __construct(
        public string $name,
        public bool $passed,
        public ?string $detail = null,
    ) {}

    public static function passed(string $name, ?string $detail = null): self
    {
        return new self($name, true, $detail);
    }

    public static function failed(string $name, ?string $detail = null): self
    {
        return new self($name, false, $detail);
    }

    /**
     * @return array{name: string, outcome: string, detail?: string}
     */
    public function toArray(): array
    {
        $row = [
            'name' => $this->name,
            'outcome' => $this->passed ? 'passed' : 'failed',
        ];

        if ($this->detail !== null) {
            $row['detail'] = $this->detail;
        }

        return $row;
    }
}
