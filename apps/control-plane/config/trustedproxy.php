<?php

declare(strict_types=1);

return [
    /*
     * Not where the balancers are configured — that is
     * `security.trusted_proxies` (TRUSTED_PROXIES). This is Illuminate's own
     * fallback key, and it is pinned to an empty list on purpose.
     *
     * Illuminate\Http\Middleware\TrustProxies::setTrustedProxyIpAddresses()
     * reads this key whenever the middleware's own list is empty — nothing
     * configured, or everything configured refused. Left undefined it is
     * null, and a null list on a request whose Host header ends in
     * `.on-forge.com` or `.on-vapor.com` makes the framework trust EVERY
     * caller, on the guess that a hosting platform's balancer is in front.
     * The Host header is the caller's to write, so any caller could send one
     * and choose its own address — and with it a fresh bucket in every
     * IP-keyed limiter — on every request.
     *
     * An empty list is not null, so that branch is unreachable and the
     * fallback trusts nobody. NoConfigurationTrustsEveryCallerTest pins it.
     */
    'proxies' => [],
];
