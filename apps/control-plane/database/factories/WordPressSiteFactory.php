<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * @extends Factory<WordPressSite>
 */
final class WordPressSiteFactory extends Factory
{
    protected $model = WordPressSite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'domain' => $this->faker->unique()->domainWord().'.test',
            'domain_source' => WordPressDomainSource::External,

            /*
             * A site starts where a real one does: asked for, nothing built.
             * A factory whose default was `ready` would let a test assert
             * against a state the platform never reached by itself.
             */
            'state' => WordPressSiteState::Requested,
            'dns_ready' => false,
            'installed' => false,
            'ssl_status' => SslStatus::Unknown,
            'locale' => 'en_US',
        ];
    }

    /** A site that is finished: resolving, installed, secured and checked. */
    public function live(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => WordPressSiteState::Ready,
            'dns_ready' => true,
            'installed' => true,
            'ssl_status' => SslStatus::Active,
            'verified_at' => now(),
            'site_url' => 'https://'.($attributes['domain'] ?? 'example.test'),
            'admin_username' => 'sitemanager',
            'wordpress_version' => '6.7.1',
        ]);
    }

    /** An install the platform stopped waiting for and must not repeat. */
    public function indeterminate(): self
    {
        return $this->state(fn (): array => [
            'state' => WordPressSiteState::Indeterminate,
            'review_reason' => 'The toolkit did not answer the installation.',
        ]);
    }
}
