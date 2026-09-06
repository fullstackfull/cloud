<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/cors.php states why the origin list must never be "*":
 *
 *     a wildcard origin is incompatible with credentialed requests for
 *     exactly that reason.
 *
 * That is not how fruitcake/php-cors behaves. CorsService::configureAllowedOrigin
 * emits the literal `*` only when credentials are NOT supported; with
 * `supports_credentials => true` — which config/cors.php hard-codes — a
 * wildcard falls through to the origin-reflection branch and echoes every
 * requesting origin alongside `Access-Control-Allow-Credentials: true`. So the
 * misconfiguration an operator would try in staging to unblock a CORS problem
 * appeared to work, and shipped: any page a signed-in customer visits could
 * then read their whole session's worth of API responses.
 *
 * CORS_ALLOWED_ORIGINS is now filtered so a wildcard leaves no allowed origin
 * at all — the portal breaks loudly instead.
 */
final class WildcardCorsOriginIsRejectedTest extends TestCase
{
    #[Test]
    public function a_wildcard_in_the_environment_never_reaches_the_origin_list(): void
    {
        $restore = getenv('CORS_ALLOWED_ORIGINS');

        try {
            foreach (['*', ' * ', 'https://portal.lynomia.test,*'] as $value) {
                putenv("CORS_ALLOWED_ORIGINS={$value}");
                $_ENV['CORS_ALLOWED_ORIGINS'] = $value;
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $value;

                $origins = (require base_path('config/security.php'))['allowed_origins'];

                $this->assertNotContains('*', $origins, "A wildcard survived CORS_ALLOWED_ORIGINS={$value}.");
            }
        } finally {
            if ($restore === false) {
                putenv('CORS_ALLOWED_ORIGINS');
                unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
            } else {
                putenv("CORS_ALLOWED_ORIGINS={$restore}");
                $_ENV['CORS_ALLOWED_ORIGINS'] = $restore;
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $restore;
            }
        }
    }

    /**
     * End to end through the fix: the operator sets CORS_ALLOWED_ORIGINS=* to
     * unblock a staging CORS problem, and the preflight answers with no
     * Access-Control-Allow-Origin at all rather than with the caller's own
     * origin. The portal stops working, which is the failure the comment in
     * config/cors.php always promised and never delivered.
     */
    #[Test]
    public function a_wildcard_environment_value_yields_no_allow_origin_at_all(): void
    {
        $restore = getenv('CORS_ALLOWED_ORIGINS');

        try {
            putenv('CORS_ALLOWED_ORIGINS=*');
            $_ENV['CORS_ALLOWED_ORIGINS'] = '*';
            $_SERVER['CORS_ALLOWED_ORIGINS'] = '*';

            config()->set('cors.allowed_origins', (require base_path('config/security.php'))['allowed_origins']);

            $response = $this->call('OPTIONS', '/api/v1/login', server: [
                'HTTP_ORIGIN' => 'https://attacker.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);

            $this->assertNull(
                $response->headers->get('Access-Control-Allow-Origin'),
                'The attacker origin was reflected back with Access-Control-Allow-Credentials.',
            );
        } finally {
            if ($restore === false) {
                putenv('CORS_ALLOWED_ORIGINS');
                unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
            } else {
                putenv("CORS_ALLOWED_ORIGINS={$restore}");
                $_ENV['CORS_ALLOWED_ORIGINS'] = $restore;
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $restore;
            }
        }
    }
}
