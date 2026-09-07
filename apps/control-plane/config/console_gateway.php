<?php

declare(strict_types=1);

return [
    /*
     * Where the gateway listens.
     *
     * Bound to loopback by default, and that is the safe default rather than a
     * limitation: the gateway terminates customer WebSockets and should sit
     * behind the same TLS terminator as the API, not be published directly.
     * A deployment that puts it on its own address sets this deliberately.
     */
    'host' => env('CONSOLE_GATEWAY_HOST', '127.0.0.1'),
    'port' => (int) env('CONSOLE_GATEWAY_PORT', 8088),

    /*
     * The path a browser connects to. The permit travels in the query string
     * because a browser's WebSocket API cannot set headers — which is exactly
     * why the permit is single-use and lives for sixty seconds.
     */
    'path' => env('CONSOLE_GATEWAY_PATH', '/console'),

    /*
     * The websocket URL handed to a browser.
     *
     * Not derived from the bind address: the gateway listens on loopback
     * behind a terminator, and what a browser must be told is the public
     * name — which only the deployment knows. Empty means consoles are not
     * offered, and the portal says so plainly rather than showing a button
     * that fails in a way the customer will read as their server being broken.
     */
    'public_url' => env('VPS_CONSOLE_GATEWAY_URL'),

    'limits' => [
        /*
         * How long a console may stay open without the platform re-checking
         * anything. A console is root access; an unbounded session is a
         * credential that outlives the reason it was granted, and a laptop
         * left open overnight is the ordinary case rather than the attack.
         */
        'session_seconds' => (int) env('CONSOLE_GATEWAY_SESSION_SECONDS', 3600),

        /*
         * How long a connection may sit with no bytes in either direction
         * before it is closed. Long enough that a customer reading their
         * screen is not disconnected; short enough that an abandoned socket
         * does not hold a console open.
         */
        'idle_seconds' => (int) env('CONSOLE_GATEWAY_IDLE_SECONDS', 300),

        /*
         * How long the client has to complete its handshake and present a
         * permit. A socket that connects and says nothing is either broken or
         * probing.
         */
        'handshake_seconds' => (int) env('CONSOLE_GATEWAY_HANDSHAKE_SECONDS', 10),

        /*
         * How long to wait for the hypervisor to accept the upstream socket.
         */
        'upstream_connect_seconds' => (int) env('CONSOLE_GATEWAY_UPSTREAM_CONNECT_SECONDS', 10),

        /*
         * Concurrent consoles this process will hold open. Bounded because
         * each one is two sockets and a buffer, and an unbounded gateway is a
         * file-descriptor exhaustion away from refusing every console on the
         * platform.
         */
        'max_sessions' => (int) env('CONSOLE_GATEWAY_MAX_SESSIONS', 200),
    ],
];
