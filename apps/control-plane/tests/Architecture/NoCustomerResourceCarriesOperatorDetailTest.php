<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The customer/operator boundary, as a property of the source rather than a
 * habit of whoever wrote the last resource.
 *
 * The audit's AS-19 listed five fields the customer surface published that
 * belong on the operator's side, and every one of them was still there at the
 * end of Wave 4 — because nothing was watching. They were not mistakes of
 * carelessness: each had a comment beside it arguing it was fine, and each
 * argument was plausible in isolation. `hardware_profile` was "the machine as
 * it would be described on a delivery note"; it was the inventory join key,
 * and the portal rendered `ded-standard-1` to the customer in both languages.
 *
 * So the rule is now mechanical. A customer-facing resource may not publish a
 * field whose name is on this list. The list is short and each entry names a
 * thing a customer cannot act on and could only misread.
 *
 * **Operator resources are exempt, by name.** An operator ticket carries the
 * assignee, an infrastructure resource carries the node — that is their whole
 * purpose. The exemption is a directory and a filename convention, not a
 * per-field suppression, so nothing can be exempted quietly.
 */
final class NoCustomerResourceCarriesOperatorDetailTest extends TestCase
{
    /**
     * Modules whose HTTP resources answer staff rather than customers.
     *
     * The Control Center *is* the operator surface: its resources are meant to
     * name nodes, clusters, credentials and drivers, and a gate that forbade
     * them would forbid the product.
     */
    private const array OPERATOR_MODULES = [
        'Admin', 'Infrastructure', 'Providers', 'Monitoring',
    ];

    /**
     * The one field this gate lets through, on the one resource that needs it.
     *
     * `StartedPaymentResource` answers "I have started paying this invoice",
     * and a client that must confirm a `client_confirmation` intent has to
     * load that gateway's own SDK to do it. The name is functional there in a
     * way it is nowhere else: it is read by the code, in the middle of a
     * payment the customer is making, rather than printed on a list of things
     * that happened. Every other payment surface publishes
     * `from_account_credit` instead, which is the customer's question.
     *
     * One resource, one field, with the reason. Not a pattern, not a module,
     * and not a per-field suppression that anybody can add to quietly.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED = [
        'StartedPaymentResource' => ['provider'],
    ];

    /** Field names a customer response must not carry, and why. */
    private const array FORBIDDEN = [
        // Who at Lynomia is holding this. The customer needs to know whether
        // it is waiting on them, which is published as `awaiting_customer`.
        'assigned_to' => 'names a member of staff',
        'assigned_to_id' => 'names a member of staff',
        'assigned_to_user_id' => 'names a member of staff',

        // Which third party this platform buys from, and which driver talks to
        // it. Neither is something the customer bought.
        'provider' => 'names a driver or a third party the platform buys from',
        'provider_driver' => 'names a driver',
        'driver' => 'names a driver',
        'provider_metadata' => "is the provider's own response object",
        'provider_task_id' => "is the provider's own job id",
        'remote_job_id' => "is the provider's own job id",

        // Where in the estate the thing physically is.
        'node_name' => 'names a hypervisor node',
        'node_id' => 'names a hypervisor node',
        'cluster_id' => 'names a cluster',
        'datastore' => 'names a datastore',
        'datacenter_id' => 'names a datacentre row',
        'rack_id' => 'names a rack',
        'rack_unit' => 'is a position in a rack',
        'asset_tag' => 'is an inventory tag',
        'hardware_profile' => 'is the inventory join key, not a description of the machine',

        // Out-of-band management, which a customer must never be handed.
        'bmc_endpoint_id' => 'names a management controller',
        'bmc_protocol' => 'names a management protocol',
        'pxe_boot_authorisation_id' => 'names a network-boot permit',

        // Credentials and the references that stand in for them.
        'credential_reference' => 'stands in for a credential',
        'credential_ref' => 'stands in for a credential',
        'secret' => 'is secret material',
        'password' => 'is secret material',

        // The engineer's text, and the operator's own notes.
        'failure_message' => "is the provider's or the engine's own sentence",
        'last_error' => "is the engine's own sentence",
        'stack_trace' => 'is a stack trace',
        'internal_notes' => 'is an operator note',
        'operator_note' => 'is an operator note',
    ];

    #[Test]
    public function no_customer_resource_publishes_a_field_from_the_operator_side(): void
    {
        $offences = [];

        foreach (self::customerResources() as $path => $source) {
            $allowed = self::ALLOWED[basename($path, '.php')] ?? [];

            foreach (self::publishedFields($source) as $field) {
                if (isset(self::FORBIDDEN[$field]) && ! in_array($field, $allowed, strict: true)) {
                    $offences[] = sprintf(
                        '%s publishes `%s`, which %s',
                        basename($path),
                        $field,
                        self::FORBIDDEN[$field],
                    );
                }
            }
        }

        sort($offences);

        $this->assertSame(
            [],
            $offences,
            "The customer surface is carrying operator detail:\n".implode("\n", $offences),
        );
    }

    #[Test]
    public function the_scan_actually_reads_the_resources_it_claims_to(): void
    {
        $resources = self::customerResources();

        // Sixty-odd customer resources at the time of writing. A scan that
        // found a handful would pass this gate while proving nothing.
        $this->assertGreaterThan(40, count($resources));

        $fields = [];

        foreach ($resources as $source) {
            $fields = [...$fields, ...self::publishedFields($source)];
        }

        $this->assertGreaterThan(300, count($fields), 'The field extraction has drifted.');
    }

    /**
     * Resource classes that answer a customer.
     *
     * @return array<string, string> path => source
     */
    private static function customerResources(): array
    {
        $found = [];
        $root = __DIR__.'/../../src/Modules';

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            $path = $file->getPathname();

            if (! str_contains($path, '/Http/Resources/') || $file->getExtension() !== 'php') {
                continue;
            }

            // Operator modules and operator-named classes are exempt: naming
            // the node is what they are for.
            if (str_starts_with($file->getBasename('.php'), 'Operator')) {
                continue;
            }

            $module = self::moduleOf($path);

            if (in_array($module, self::OPERATOR_MODULES, strict: true)) {
                continue;
            }

            $source = file_get_contents($path);

            if ($source !== false) {
                $found[$path] = $source;
            }
        }

        return $found;
    }

    private static function moduleOf(string $path): string
    {
        preg_match('#/Modules/([A-Za-z]+)/#', $path, $matches);

        return $matches[1] ?? '';
    }

    /**
     * The keys a resource puts in its payload, at any nesting depth.
     *
     * The same extraction the specification gate uses: a quoted key followed
     * by `=>` at the start of a line. Nesting is deliberately included —
     * `provider` inside a nested object reaches the customer exactly as surely
     * as one at the top level.
     *
     * @return list<string>
     */
    private static function publishedFields(string $source): array
    {
        preg_match_all("/^\s*'([a-z0-9_]+)' =>/m", $source, $matches);

        return $matches[1];
    }
}
