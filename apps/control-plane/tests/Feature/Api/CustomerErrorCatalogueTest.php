<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The customer error catalogue is complete, in both languages, or the build
 * is red.
 *
 * The audit found 137 customer error codes and 9 translations. Nothing about
 * the code stops that from happening again — a new exception is a new code,
 * and a new code with no sentence is answered with the engineer's message —
 * so this test derives the set of codes from the source of truth (the
 * exception classes of the modules that serve customer routes, plus the
 * codes the renderer and the customer controllers mint themselves) and
 * requires every one to have an English and an Arabic sentence.
 *
 * The derivation is deliberately from the customer modules only. The operator
 * modules (Infrastructure, Providers, Admin, Provisioning's retry and drift
 * machinery, Monitoring) answer staff, in English, with the engineer's
 * message, and translating them would be translating a log.
 */
final class CustomerErrorCatalogueTest extends TestCase
{
    /**
     * Modules whose exceptions can escape through a customer route, and which
     * must therefore have a sentence for every code they can raise.
     *
     * **The list is a floor, not the list.** It was a hand-maintained constant
     * and it had already drifted: Wave 4 added the Activity module with routes
     * under /api/v1 and nobody added it here, so for one whole wave a new
     * customer module was outside the gate that exists to stop exactly that.
     * It happened to raise no exceptions, which is luck rather than design.
     *
     * So the set is now derived from `routes/v1` and this constant only adds
     * the modules whose exceptions escape through *somebody else's* routes —
     * a domain refusal raised while placing an order, a readiness answer
     * raised from the catalogue. Those cannot be found by reading route files
     * and are the only thing worth maintaining by hand.
     *
     * @see modulesServingCustomerRoutes()
     */
    private const array MODULES_WITHOUT_ROUTES_OF_THEIR_OWN = [
        // Raised while placing an order or reading a plan, never from a route
        // of its own.
        'Compute', 'ProductReadiness', 'Shared', 'Subscriptions', 'Catalog',
    ];

    /**
     * Customer modules whose requests are all operator requests: their fields
     * are named for staff, in English, and are not customer fields.
     */
    private const array MODULES_WITHOUT_CUSTOMER_REQUESTS = ['Compute', 'ProductReadiness', 'Shared'];

    /**
     * Modules serving /api/v1 that raise nothing and validate nothing, so
     * neither derivation finds anything in them. Named so the floor assertion
     * below cannot be satisfied by a module quietly disappearing.
     */
    private const array MODULES_WITH_NO_CODES = ['Activity'];

    /**
     * Modules that serve a customer route but whose exceptions cannot escape
     * through it, with the reason.
     *
     * Provisioning's one customer route is a read — the events on a service —
     * and its exceptions belong to the engine: capacity, timeouts, adoption,
     * retry refusals, drift review. They are raised by jobs and by operator
     * endpoints, they are answered to staff in English with the engineer's
     * message, and translating them would be translating a log.
     *
     * Kept as a named list rather than folded into the derivation, so that a
     * second module claiming the same exemption is a decision somebody makes
     * here.
     */
    private const array OPERATOR_ONLY_EXCEPTIONS = ['Provisioning'];

    /** Codes minted by the renderer in bootstrap/app.php rather than by an exception. */
    private const array RENDERER_CODES = [
        'validation.failed', 'auth.unauthenticated', 'auth.forbidden', 'auth.csrf_token_mismatch',
        'resource.not_found', 'server.error', 'request_failed',
    ];

    #[Test]
    public function every_code_a_customer_module_can_raise_has_an_english_and_an_arabic_sentence(): void
    {
        $codes = $this->customerCodes();
        $this->assertGreaterThan(200, count($codes), 'The derivation found suspiciously few codes; the regex or the paths have drifted.');

        $english = $this->flatten((array) require base_path('lang/en/errors.php'));
        $arabic = $this->flatten((array) require base_path('lang/ar/errors.php'));

        $missingEnglish = array_values(array_diff($codes, array_keys($english)));
        $missingArabic = array_values(array_diff($codes, array_keys($arabic)));

        $this->assertSame([], $missingEnglish, 'Customer error codes with no English sentence in lang/en/errors.php');
        $this->assertSame([], $missingArabic, 'Customer error codes with no Arabic sentence in lang/ar/errors.php');
    }

    #[Test]
    public function the_two_catalogues_carry_exactly_the_same_codes_and_placeholders(): void
    {
        $english = $this->flatten((array) require base_path('lang/en/errors.php'));
        $arabic = $this->flatten((array) require base_path('lang/ar/errors.php'));

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($arabic))), 'Codes only English has');
        $this->assertSame([], array_values(array_diff(array_keys($arabic), array_keys($english))), 'Codes only Arabic has');

        foreach ($english as $code => $sentence) {
            $this->assertNotSame('', trim($sentence), $code);
            $this->assertNotSame('', trim($arabic[$code]), $code);
            $this->assertSame($this->placeholders($sentence), $this->placeholders($arabic[$code]), "Placeholders differ for {$code}");
            // Arabic is Arabic: a sentence with no Arabic letters is an English
            // sentence that was pasted into the wrong file.
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arabic[$code], "Not Arabic: {$code}");
        }
    }

    #[Test]
    public function no_customer_sentence_names_an_internal_thing(): void
    {
        // Words a customer sentence must never contain: each names a layer of
        // the platform a customer cannot see and could only misread.
        $forbidden = ['BMC', 'PXE', 'Proxmox', 'Redfish', 'IPMI', 'driver', 'adapter', 'node ', 'cluster', 'hypervisor', 'stack trace', 'exception', 'SQL', 'credential reference', 'Cloudflare', 'cPanel', 'DirectAdmin'];

        foreach (['en', 'ar'] as $locale) {
            foreach ($this->flatten((array) require base_path("lang/{$locale}/errors.php")) as $code => $sentence) {
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsStringIgnoringCase($word, $sentence, "{$locale} {$code} names \"{$word}\"");
                }
            }
        }
    }

    #[Test]
    public function every_customer_field_and_request_sentence_exists_in_both_validation_catalogues(): void
    {
        $english = (array) require base_path('lang/en/validation.php');
        $arabic = (array) require base_path('lang/ar/validation.php');

        // Every rule the framework ships has an Arabic line.
        $rules = array_keys($this->flatten(array_diff_key($english, ['custom' => 1, 'attributes' => 1, 'requests' => 1])));
        $arabicRules = array_keys($this->flatten(array_diff_key($arabic, ['custom' => 1, 'attributes' => 1, 'requests' => 1])));
        $this->assertSame([], array_values(array_diff($rules, $arabicRules)), 'Validation rules with no Arabic line');

        // Every field a customer request validates is named in both languages.
        $fields = $this->customerFields();
        $this->assertGreaterThan(80, count($fields));
        foreach (['en' => $english, 'ar' => $arabic] as $locale => $catalogue) {
            $named = array_keys((array) $catalogue['attributes']);
            $this->assertSame([], array_values(array_diff($fields, $named)), "Customer fields with no {$locale} name");
        }

        // Every request-specific sentence exists in both languages with the same placeholders.
        $requestsEn = $this->flatten((array) $english['requests']);
        $requestsAr = $this->flatten((array) $arabic['requests']);
        $this->assertSame(array_keys($requestsEn), array_keys($requestsAr));
        foreach ($requestsAr as $key => $sentence) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $sentence, $key);
        }

        // And every `validation.requests.*` key the code asks for exists.
        foreach ($this->referencedRequestKeys() as $key) {
            $this->assertArrayHasKey($key, $requestsEn, "Code asks for validation.requests.{$key}, which no catalogue has");
        }
    }

    #[Test]
    public function the_module_list_is_read_from_the_routes_rather_than_maintained_by_hand(): void
    {
        $derived = self::customerModules();

        /*
         * Every module that serves a customer route is in the set, which is
         * the property the hand-maintained constant did not have. Activity is
         * the specific one it was missing.
         */
        foreach (['Activity', 'ApiKeys', 'Backups', 'Billing', 'Dedicated', 'Dns', 'Domains',
            'Identity', 'Ipam', 'Notifications', 'Orders', 'Payments', 'SharedHosting',
            'Support', 'Vps', 'Wallet'] as $module) {
            $this->assertContains($module, $derived, "{$module} serves customer routes and the gate does not read it");
        }

        // And the modules that raise nothing still exist, so the floor above
        // cannot be met by one of them being deleted.
        foreach (self::MODULES_WITH_NO_CODES as $module) {
            $this->assertDirectoryExists(base_path("src/Modules/{$module}"));
        }
    }

    /**
     * Every module a customer request can reach, read from the route files.
     *
     * @return list<string>
     */
    private static function customerModules(): array
    {
        $modules = self::MODULES_WITHOUT_ROUTES_OF_THEIR_OWN;

        $files = [
            ...File::glob(base_path('routes/v1/*.php')),
            base_path('routes/api_v1.php'),
        ];

        foreach ($files as $file) {
            preg_match_all(
                '/Lynomia\\\\Modules\\\\([A-Za-z]+)\\\\/',
                (string) file_get_contents($file),
                $matches,
            );

            array_push($modules, ...$matches[1]);
        }

        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }

    /**
     * @return list<string>
     */
    private function customerCodes(): array
    {
        $codes = self::RENDERER_CODES;

        foreach (array_diff(self::customerModules(), self::OPERATOR_ONLY_EXCEPTIONS) as $module) {
            $files = [
                ...File::glob(base_path("src/Modules/{$module}/Domain/Exceptions/*.php")),
                ...File::glob(base_path("src/Modules/{$module}/Domain/Enums/*Refusal*.php")),
            ];

            foreach ($files as $file) {
                foreach (file($file) ?: [] as $line) {
                    // Documentation and config keys mention dotted names that
                    // are not codes.
                    if (str_contains($line, 'config(') || str_starts_with(trim($line), '*') || str_starts_with(trim($line), '//')) {
                        continue;
                    }

                    preg_match_all("/'([a-z_]+(?:\\.[a-z_]+){1,2})'/", $line, $matches);
                    array_push($codes, ...$matches[1]);
                }
            }
        }

        // Codes the customer controllers and the HTTP layer mint directly.
        $sources = [
            ...File::glob(base_path('src/Http/**/*.php')),
            base_path('bootstrap/app.php'),
        ];
        foreach (self::customerModules() as $module) {
            array_push($sources, ...File::glob(base_path("src/Modules/{$module}/Http/Controllers/*.php")));
        }
        foreach ($sources as $file) {
            preg_match_all("/ApiError::make\\(\\s*'([a-z_.]+)'/", (string) file_get_contents($file), $matches);
            array_push($codes, ...$matches[1]);
        }

        $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => ! str_ends_with($code, '.') && ! str_starts_with($code, 'http.'))));
        sort($codes);

        return $codes;
    }

    /**
     * Every field name a customer request validates, in the form the
     * validator reports it (`items.*.plan_id`).
     *
     * @return list<string>
     */
    private function customerFields(): array
    {
        $fields = [];
        foreach (array_diff(self::customerModules(), self::MODULES_WITHOUT_CUSTOMER_REQUESTS) as $module) {
            $files = [
                ...File::glob(base_path("src/Modules/{$module}/Http/Requests/*.php")),
                ...File::glob(base_path("src/Modules/{$module}/Http/Controllers/*.php")),
            ];
            foreach ($files as $file) {
                foreach (file($file) ?: [] as $line) {
                    /*
                     * A rule list, not any array. `'billing' => [` in a
                     * controller building a response used to match this, which
                     * is how three response keys turned up as fields with no
                     * name in either catalogue the first time the module list
                     * was derived from the routes rather than hand-written.
                     */
                    if (preg_match("/^\\s*'([a-z_]+(?:\\.\\*)?(?:\\.[a-z_]+)*)' => (?:\\[\\s*'(?:required|nullable|sometimes|string|integer|boolean|array|email|accepted|bail|present|prohibited|date|ulid|uuid|numeric|in|max|min|regex|confirmed|exists|image|file|timezone|url|ip|json)|'(?:required|nullable|sometimes|string|integer|boolean|array|email|accepted|bail|present|prohibited)\\b)/", $line, $m) === 1) {
                        $fields[] = $m[1];
                    }
                }
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function referencedRequestKeys(): array
    {
        $keys = [];
        foreach ([...File::allFiles(base_path('src'))] as $file) {
            preg_match_all("/__\\('validation\\.requests\\.([a-z_.]+)'/", $file->getContents(), $matches);
            array_push($keys, ...$matches[1]);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $sentence): array
    {
        preg_match_all('/:([a-z_]+)/', $sentence, $matches);
        $found = array_unique($matches[1]);
        sort($found);

        return array_values($found);
    }
}
