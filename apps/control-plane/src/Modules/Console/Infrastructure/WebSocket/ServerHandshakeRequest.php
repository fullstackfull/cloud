<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure\WebSocket;

/**
 * An inbound upgrade request, parsed.
 *
 * @immutable
 */
final readonly class ServerHandshakeRequest
{
    /**
     * @param  array<string, string>  $headers  Lower-cased names.
     */
    public function __construct(
        public string $target,
        public array $headers,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The path with the query string removed.
     */
    public function path(): string
    {
        $query = strpos($this->target, '?');

        return $query === false ? $this->target : substr($this->target, 0, $query);
    }

    /**
     * One query parameter.
     *
     * The console permit travels here rather than in a header because a
     * browser's WebSocket API cannot set headers — which is also why the token
     * is single-use and lives for a minute: a query string is the one place a
     * secret is most likely to be written down by something in the middle.
     */
    public function query(string $name): ?string
    {
        $query = strpos($this->target, '?');

        if ($query === false) {
            return null;
        }

        parse_str(substr($this->target, $query + 1), $parameters);

        $value = $parameters[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
