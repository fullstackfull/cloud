<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The retry policy stated on its own, because it is the rule that decides
 * whether a customer can end up with two servers.
 */
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function only_failures_that_built_nothing_are_retried(): void
    {
        $this->assertTrue(FailureClass::Transient->isAutomaticallyRetryable());
        $this->assertTrue(FailureClass::Capacity->isAutomaticallyRetryable());
        $this->assertFalse(FailureClass::Permanent->isAutomaticallyRetryable());
    }

    #[Test]
    public function a_timeout_is_never_retried_even_if_configuration_says_otherwise(): void
    {
        // A config edit — or a copied .env, or a well-meaning operator — must
        // not be able to turn "we stopped waiting" into "build another one".
        config(['provisioning.retry.retryable_classes' => ['transient', 'capacity', 'timeout']]);

        $this->assertFalse(FailureClass::Timeout->isAutomaticallyRetryable());
    }

    #[Test]
    public function a_timeout_quarantines_and_needs_a_person(): void
    {
        $this->assertTrue(FailureClass::Timeout->requiresQuarantine());
        $this->assertTrue(FailureClass::Timeout->requiresReview());

        foreach ([FailureClass::Transient, FailureClass::Permanent, FailureClass::Capacity] as $class) {
            $this->assertFalse($class->requiresQuarantine(), "{$class->value} must release, not quarantine.");
            $this->assertFalse($class->requiresReview(), "{$class->value} must not need review on its own.");
        }
    }

    #[Test]
    public function the_shipped_configuration_does_not_list_timeout_as_retryable(): void
    {
        /** @var list<string> $retryable */
        $retryable = config('provisioning.retry.retryable_classes');

        $this->assertNotContains('timeout', $retryable);
    }

    #[Test]
    public function each_kind_is_given_a_deadline_that_matches_the_work(): void
    {
        // One clock for a power-on and a dedicated server install would either
        // abandon the install or let a stuck reboot hold its addresses for an
        // hour and a half.
        $this->assertSame(5_400, ProvisioningJobKind::ProvisionDedicated->defaultTimeoutSeconds());
        $this->assertSame(300, ProvisioningJobKind::DestroyVps->defaultTimeoutSeconds());
        $this->assertSame(900, ProvisioningJobKind::CreateVps->defaultTimeoutSeconds());
        $this->assertSame(900, ProvisioningJobKind::Start->defaultTimeoutSeconds());
    }

    #[Test]
    public function only_the_kinds_that_build_something_can_produce_a_duplicate(): void
    {
        $this->assertTrue(ProvisioningJobKind::CreateVps->createsResource());
        $this->assertTrue(ProvisioningJobKind::CreateHostingAccount->createsResource());
        $this->assertTrue(ProvisioningJobKind::ProvisionDedicated->createsResource());

        foreach ([ProvisioningJobKind::Start, ProvisioningJobKind::Restart, ProvisioningJobKind::Resize] as $kind) {
            $this->assertFalse($kind->createsResource());
        }
    }
}
