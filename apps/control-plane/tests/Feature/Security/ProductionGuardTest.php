<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Providers\ProviderRegistryServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProductionGuardTest extends TestCase
{
    private function guard(): ProviderRegistryServiceProvider
    {
        return new ProviderRegistryServiceProvider($this->app);
    }

    /**
     * Removes APP_ENV from every place Laravel reads it, and returns the
     * closure that puts it back.
     *
     * PHPUnit sets APP_ENV in $_ENV and $_SERVER as well as the process
     * environment, so clearing only one of the three leaves the value visible
     * and the branch under test unreachable — which is how the first version of
     * this test passed for the wrong reason.
     *
     * @return callable(): void
     */
    private function withoutAppEnv(): callable
    {
        $process = getenv('APP_ENV');
        $env = $_ENV['APP_ENV'] ?? null;
        $server = $_SERVER['APP_ENV'] ?? null;

        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        return static function () use ($process, $env, $server): void {
            if (is_string($process)) {
                putenv('APP_ENV='.$process);
            } else {
                putenv('APP_ENV');
            }

            if ($env !== null) {
                $_ENV['APP_ENV'] = $env;
            } else {
                unset($_ENV['APP_ENV']);
            }

            if ($server !== null) {
                $_SERVER['APP_ENV'] = $server;
            } else {
                unset($_SERVER['APP_ENV']);
            }
        };
    }

    #[Test]
    public function a_production_deployment_with_a_fake_provider_refuses_to_boot(): void
    {
        /*
         * The worst failure this platform can have is a production system that
         * reports payments captured and servers created while doing neither.
         * This guard turns that into a deployment error visible in the first
         * thirty seconds instead of a support ticket a week later.
         */
        config()->set('billing.providers', [
            'payment' => 'stripe',
            'compute' => 'fake',
            'hosting' => 'cpanel',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/compute/');

        $this->guard()->assertNoFakeProviders();
    }

    #[Test]
    public function the_error_names_every_offending_provider_not_just_the_first(): void
    {
        config()->set('billing.providers', [
            'payment' => 'fake',
            'compute' => 'fake',
            'dns' => 'cloudflare',
        ]);

        try {
            $this->guard()->assertNoFakeProviders();
            $this->fail('Expected the guard to refuse this configuration.');
        } catch (RuntimeException $e) {
            // An operator fixing one variable at a time, redeploying between
            // each, is a bad afternoon.
            $this->assertStringContainsString('payment', $e->getMessage());
            $this->assertStringContainsString('compute', $e->getMessage());
            $this->assertStringNotContainsString('dns', $e->getMessage());
        }
    }

    #[Test]
    public function a_suspension_policy_the_platform_does_not_implement_refuses_to_boot(): void
    {
        /*
         * The value is only read when a customer stops paying. Left to be
         * discovered then, a typo surfaces as an exception inside a queued
         * listener on the night the suspension was supposed to happen — and
         * the operator who set it has every reason to believe suspension is
         * configured.
         */
        config()->set('compute.suspension_policy', 'power_off_and_locked');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/suspension_policy/');

        $this->guard()->assertSuspensionPolicyIsUnderstood();
    }

    #[Test]
    public function the_suspension_policy_error_lists_the_values_that_would_work(): void
    {
        config()->set('compute.suspension_policy', 'lock');

        try {
            $this->guard()->assertSuspensionPolicyIsUnderstood();
            $this->fail('Expected the guard to refuse this configuration.');
        } catch (RuntimeException $e) {
            foreach (SuspensionPolicy::cases() as $policy) {
                $this->assertStringContainsString($policy->value, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_deployment_that_says_nothing_about_suspension_gets_enforcement(): void
    {
        // Silence must not mean bookkeeping. The strictest policy is the
        // default so that an operator who never heard of the setting still
        // gets a suspension the customer cannot undo.
        config()->set('compute.suspension_policy', null);

        $this->guard()->assertSuspensionPolicyIsUnderstood();

        $this->assertSame(SuspensionPolicy::PowerOffAndLock, SuspensionPolicy::configured());
    }

    #[Test]
    public function the_guard_stands_down_when_nothing_at_all_has_been_configured(): void
    {
        /*
         * No environment file and no APP_ENV: this is a build step, not a
         * deployment. Composer's package discovery, `artisan test` on a fresh
         * clone and the static analyser booting the application all arrive here,
         * and all three were being refused with a message about production.
         */
        config()->set('billing.providers', ['payment' => 'fake']);
        $this->app->useEnvironmentPath('/nonexistent-for-this-test');

        $restore = $this->withoutAppEnv();

        try {
            $this->app->detectEnvironment(static fn (): string => 'production');

            // No exception: boot() is what decides, and it stands down here.
            $this->guard()->boot();

            $this->addToAssertionCount(1);
        } finally {
            $restore();
        }
    }

    #[Test]
    public function the_guard_still_refuses_a_container_that_chose_production(): void
    {
        // The twelve-factor case: configuration in real environment variables
        // rather than a file. "production" was chosen, so the guard applies.
        config()->set('billing.providers', ['payment' => 'fake']);
        $this->app->useEnvironmentPath('/nonexistent-for-this-test');

        $restore = $this->withoutAppEnv();
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        try {
            $this->app->detectEnvironment(static fn (): string => 'production');

            $this->expectException(RuntimeException::class);

            $this->guard()->boot();
        } finally {
            $restore();
        }
    }

    #[Test]
    public function the_refusal_says_when_production_was_a_default_rather_than_a_decision(): void
    {
        /*
         * With no environment file, Laravel falls back to APP_ENV=production and
         * every provider to the packaged fake, so this guard fires during
         * `composer install`'s package discovery on a machine that has not
         * written its .env yet. That is the guard working correctly in a context
         * nobody intended it for, and it cost six red CI runs before anybody
         * read past the word "production" in the message.
         */
        config()->set('billing.providers', ['payment' => 'fake']);

        $this->app->useEnvironmentPath('/nonexistent-for-this-test');

        try {
            $this->guard()->assertNoFakeProviders();
            $this->fail('Expected the guard to refuse this configuration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No environment file was found', $e->getMessage());
            $this->assertStringContainsString('set APP_ENV', $e->getMessage());
        }
    }

    #[Test]
    public function the_refusal_stays_plain_when_the_environment_was_chosen(): void
    {
        config()->set('billing.providers', ['payment' => 'fake']);

        try {
            $this->guard()->assertNoFakeProviders();
            $this->fail('Expected the guard to refuse this configuration.');
        } catch (RuntimeException $e) {
            // The environment file exists here, so the deployment really is
            // configured with a fake and there is nothing to explain away.
            $this->assertStringNotContainsString('No environment file was found', $e->getMessage());
        }
    }

    #[Test]
    public function a_production_deployment_whose_sessions_cannot_be_listed_refuses_to_boot(): void
    {
        /*
         * Found by a browser test, not by this suite: the shipped configuration
         * put sessions in Redis while the account-security screen reads the
         * `sessions` table, so a signed-in customer was shown "No active
         * sessions" and "sign out other devices" answered 204 without deleting
         * anything. The unit tests never caught it because they insert the rows
         * the controller reads.
         */
        config()->set('session.driver', 'redis');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SESSION_DRIVER/');

        $this->guard()->assertSessionsAreEnumerable();
    }

    #[Test]
    public function the_database_session_driver_is_accepted(): void
    {
        config()->set('session.driver', 'database');

        $this->guard()->assertSessionsAreEnumerable();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_shipped_configuration_stores_sessions_where_they_can_be_revoked(): void
    {
        // The example file is what an operator copies on day one, so it is the
        // file that decides whether the feature works in the field.
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($example);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=database$/m', $example);
    }

    #[Test]
    public function the_check_is_case_insensitive(): void
    {
        config()->set('billing.providers', ['payment' => 'FAKE']);

        $this->expectException(RuntimeException::class);

        $this->guard()->assertNoFakeProviders();
    }

    #[Test]
    public function a_fully_real_configuration_passes(): void
    {
        // `dns` is deliberately absent. This build contains exactly one
        // reverse-DNS driver and it is the fake, so there is no value for that
        // key a production deployment could legally carry — which is a fact
        // about the build, recorded in docs/build-status.md, not a gap in this
        // test. An unset key is how a deployment says it publishes no PTRs.
        config()->set('billing.providers', [
            'payment' => 'stripe',
            'compute' => 'proxmox',
            'dedicated' => 'redfish',
            'hosting' => 'cpanel',
            'backup' => 'proxmox',
        ]);

        $this->guard()->assertNoFakeProviders();

        $this->addToAssertionCount(1);
    }

    /*
     * ---------------------------------------------------------------------
     * A driver that does not exist
     * ---------------------------------------------------------------------
     *
     * Refusing the fake is only half of it. `PAYMENT_PROVIDER=myfatoorah` and
     * `DNS_PROVIDER=cloudflare` are settings this build cannot honour, and they
     * used to pass this guard: production booted, reported healthy, and threw
     * the first time a customer tried to pay or an operator set a PTR. Failing
     * at boot is the whole point of having the guard at all.
     *
     * Only the two families whose driver actually comes from configuration are
     * checked. Compute, dedicated and hosting resolve per row — from the
     * cluster's driver, the endpoint's protocol and the node's panel — so a
     * config value for them names nothing and cannot be validated against
     * anything.
     */

    #[Test]
    public function a_payment_driver_this_build_does_not_contain_refuses_to_boot(): void
    {
        config()->set('billing.providers', ['payment' => 'myfatoorah']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment.*myfatoorah/s');

        $this->guard()->assertEveryConfiguredDriverExists();
    }

    #[Test]
    public function a_dns_driver_this_build_does_not_contain_refuses_to_boot(): void
    {
        // Route 53 is a real provider with no adapter here. `cloudflare` stood
        // in this test until the adapter was written, and swapping it out is
        // the honest edit: the guarantee under test is "a driver that is not
        // here is refused", not "cloudflare is not here".
        config()->set('billing.providers', ['payment' => 'stripe', 'dns' => 'route53']);

        try {
            $this->guard()->assertEveryConfiguredDriverExists();
            $this->fail('A DNS driver with no adapter was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('route53', $e->getMessage());
            // The message has to say what it will accept, or an operator is
            // left guessing at the one string that would have worked.
            $this->assertStringContainsString('cloudflare', $e->getMessage());
        }
    }

    #[Test]
    public function the_dns_driver_must_exist_on_both_sides_of_the_one_key(): void
    {
        // `billing.providers.dns` drives two adapters — forward DNS and
        // reverse DNS — and a driver present in one list and absent from the
        // other would boot and then fail on whichever half was missing. The
        // guard checks the intersection, so both lists have to hold it.
        config()->set('billing.providers', ['payment' => 'stripe', 'dns' => 'cloudflare']);

        $this->guard()->assertEveryConfiguredDriverExists();

        $this->assertContains('cloudflare', DnsProviderFactory::drivers());
        $this->assertContains('cloudflare', ReverseDnsProviderFactory::drivers());
    }

    #[Test]
    public function drivers_this_build_does_contain_are_accepted(): void
    {
        config()->set('billing.providers', [
            'payment' => 'stripe',
            // The fake exists, so this check accepts it. Refusing it in
            // production is the other method's job, and keeping the two
            // separate is what lets each be tested for one thing.
            'dns' => 'fake',
            // Named here on purpose: these three are not resolved from
            // configuration, so whatever they say must not fail the check.
            'compute' => 'proxmox',
            'dedicated' => 'redfish',
            'hosting' => 'cpanel',
            // `pbs` used to stand here as a plausible-looking value for a
            // driver that did not exist. It does now, and it is called
            // proxmox — the hypervisor is what the platform asks, and PBS is
            // where the archive lands.
            'backup' => 'proxmox',
        ]);

        $this->guard()->assertEveryConfiguredDriverExists();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function development_keeps_using_fakes_without_complaint(): void
    {
        // The guard must not fire outside production, or no one could develop.
        config()->set('billing.providers', ['payment' => 'fake', 'compute' => 'fake']);

        $this->guard()->boot();

        $this->addToAssertionCount(1);
    }
}
