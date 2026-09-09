<?php

declare(strict_types=1);

namespace Lynomia\Modules\ObjectStorage\Domain\Contracts;

use Lynomia\Modules\ObjectStorage\Domain\DTOs\Bucket;
use Lynomia\Modules\ObjectStorage\Domain\DTOs\BucketUsage;
use Lynomia\Modules\ObjectStorage\Domain\DTOs\IssuedAccessKey;
use Lynomia\Modules\ObjectStorage\Domain\DTOs\LifecycleRule;

/**
 * An S3-compatible object store the platform would sell buckets on.
 *
 * ===========================================================================
 * A TYPED SEAT, NOT A PRODUCT
 * ===========================================================================
 *
 * No implementation, no driver, no cluster, no product for sale:
 * `Product::ObjectStorage` is prepared and capped below production. The
 * platform has never talked to a Ceph RGW, a MinIO or a Garage; nothing
 * here says which it would be. What exists is one method per capability
 * the readiness engine asks about, so an adapter and the questions line
 * up on the day there is one.
 *
 * Two rules are already decided here because the shape of the interface
 * decides them. An access key's secret is returned once, in
 * {@see IssuedAccessKey}, and by nothing else on this interface: there is
 * no `readAccessKey`, so nothing on the platform can be built that shows
 * a secret twice. And buckets are the customer's names under a tenant the
 * adapter derives from the account; a method that took a raw tenant
 * would be a method that reads somebody else's bucket.
 */
interface ObjectStorageProvider
{
    public function createBucket(string $customerRef, string $bucket, ?int $quotaBytes): Bucket;

    /** Refused by the store while the bucket has objects; this platform never empties one for a customer. */
    public function deleteBucket(string $customerRef, string $bucket): void;

    /**
     * @return list<Bucket>
     */
    public function listBuckets(string $customerRef): array;

    public function quota(string $customerRef, string $bucket, ?int $quotaBytes): Bucket;

    public function usage(string $customerRef, string $bucket): BucketUsage;

    /** The secret is in the return value once, and nowhere else, ever. */
    public function issueAccessKey(string $customerRef, string $label): IssuedAccessKey;

    public function revokeAccessKey(string $customerRef, string $accessKeyId): void;

    /** The endpoint a customer's client is pointed at. Per store, not per bucket. */
    public function endpoint(): string;

    public function versioning(string $customerRef, string $bucket, bool $enabled): Bucket;

    /**
     * @param  list<LifecycleRule>  $rules
     */
    public function lifecycle(string $customerRef, string $bucket, array $rules): Bucket;
}
