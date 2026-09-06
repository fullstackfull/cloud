<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Providers;

use InvalidArgumentException;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use SensitiveParameter;

/**
 * How to reach one out-of-band controller, credential included.
 *
 * Built per operation from the endpoint row plus configuration, and never
 * stored: a BMC password in the database is a BMC password in every backup,
 * every read replica and every support export — and an iLO or IPMI credential
 * is complete control of the physical host and every tenant sharing it.
 *
 * The object is bound to ONE endpoint id, and adapters check the endpoint they
 * are handed against it. That check exists because the mistake it prevents
 * cannot be undone: a wrong hypervisor id destroys one customer's virtual
 * machine, a wrong BMC address power cycles or reinstalls a physical host
 * somebody else is running on.
 *
 * @immutable
 */
final readonly class BmcConnection
{
    private const int FALLBACK_TIMEOUT_SECONDS = 60;

    /**
     * @param  string  $endpointId  The bmc_endpoints row this connection was built for.
     * @param  bool  $verifyTls  Defaults to true everywhere it is constructed. A controller
     *                           with a self-signed certificate turns verification off on its
     *                           own row, which keeps the exception visible and local instead
     *                           of an environment variable that silently disables it fleet-wide.
     * @param  string  $systemId  The Redfish system identifier under /redfish/v1/Systems. Vendors
     *                            disagree — HPE uses "1", Dell "System.Embedded.1" — so it is
     *                            configuration, not a constant.
     */
    public function __construct(
        public string $endpointId,
        public BmcProtocol $protocol,
        public string $address,
        public int $port,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public bool $verifyTls = true,
        public int $timeoutSeconds = self::FALLBACK_TIMEOUT_SECONDS,
        public string $systemId = '1',
    ) {
        if (trim($address) === '') {
            throw new InvalidArgumentException('A BMC connection needs an address.');
        }

        if (trim($username) === '' || $password === '') {
            throw new InvalidArgumentException(
                'A BMC connection needs a username and a password. '
                .'Anonymous access to a controller with power control is not a supported configuration.'
            );
        }
    }

    /**
     * The controller's HTTPS root.
     *
     * There is no plaintext variant and no configuration switch to add one.
     * Redfish authenticates with HTTP Basic, so an unencrypted request would
     * put the credential for a physical machine on the wire in base64.
     */
    public function baseUrl(): string
    {
        return sprintf('https://%s:%d', $this->address, $this->port);
    }

    /**
     * Every string that must never survive into a message, a log line or an
     * exception context.
     *
     * The password is the obvious one. The Basic header is included because it
     * is the password in a different encoding, and a redactor matching on the
     * plaintext would sail straight past it.
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        return [
            $this->password,
            base64_encode($this->username.':'.$this->password),
            'Basic '.base64_encode($this->username.':'.$this->password),
        ];
    }
}
