<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * One Cloudflare zone, held in memory and answered over `Http::fake()`.
 *
 * Written for F-11, whose defining trap was an oracle that reproduced the
 * defect it was meant to catch: a fake that kept one record per
 * `(type, name)` cannot hold a round-robin pair, so an adapter that collapsed
 * the pair onto `$existing[0]` passed every test written against it. This
 * simulator keys records by the identifier it hands out, and nothing else, so
 * a name holds as many records as were written to it — which is the one
 * property of the real API that everything below depends on.
 *
 * What it models, and where each behaviour comes from:
 *
 *  - `GET /zones/{id}/dns_records` narrows by `type` and `name` and pages by
 *    `per_page`/`page`, answering `result_info.total_pages`. The adapter's own
 *    request shape (`CloudflareDnsProvider::records()`) is the source; the
 *    page size is honoured as asked, so an adapter that reads one page of a
 *    zone larger than a page is seen to lose the rest.
 *  - `POST` creates and hands out a new identifier; `PUT` replaces the record
 *    under an identifier; `DELETE` removes it. A payload is stored as sent, so
 *    a CAA published as `data` with no `content` is listed back that way —
 *    exactly the asymmetry the adapter has to bridge when it reads.
 *  - **An identifier the zone does not hold answers 404.** That status is
 *    invented rather than derived: what Cloudflare answers for an unknown
 *    record id is nowhere in this repository. No behaviour of the adapter may
 *    rest on it, and none does — the adapter reads before it writes, so it
 *    never sends an identifier the read did not just return.
 *  - {@see self::loseTheNextAnswer()} applies the next write and then drops
 *    the connection, which is the case the Timeout Rule is about: the write
 *    landed and the caller was told nothing. {@see self::failTheNextWrite()}
 *    drops it before anything is applied. The caller cannot tell them apart,
 *    and a test needs both.
 *
 * Every write request is counted, so a test can say "nothing was written" by
 * measuring it rather than by trusting the adapter's return value.
 */
final class CloudflareZoneSimulator
{
    public const string BASE = 'https://api.cloudflare.test/client/v4';

    public const string ZONE_ID = 'zone-1';

    /** @var array<string, array<string, mixed>> id => row, in insertion order */
    private array $records = [];

    private int $nextId = 1;

    private int $writes = 0;

    private bool $loseNextAnswer = false;

    private bool $failNextWrite = false;

    public function __construct(
        public readonly string $zoneName = 'lynomia.test',
    ) {}

    /**
     * Configure the adapter for this simulator and route every request to it.
     */
    public static function install(string $zoneName = 'lynomia.test'): self
    {
        config()->set('services.cloudflare', [
            'api_token' => 'cf-simulator-token-0123456789',
            'account_id' => null,
            'base_url' => self::BASE,
            'timeout' => 5,
            'verify_tls' => true,
        ]);

        $simulator = new self($zoneName);

        Http::fake(fn (Request $request) => $simulator->answer($request));

        return $simulator;
    }

    /**
     * Put a record in the zone the way somebody using the provider's own
     * console would: no request from the adapter, and an identifier the
     * platform has never seen.
     *
     * @param  array<string, mixed>  $row
     */
    public function holds(array $row): string
    {
        $id = 'rec-'.$this->nextId++;
        $this->records[$id] = [...$row, 'id' => $id];

        return $id;
    }

    /**
     * Change a record behind the platform's back, as a console edit would.
     *
     * @param  array<string, mixed>  $changes
     */
    public function edit(string $id, array $changes): void
    {
        $this->records[$id] = [...$this->records[$id], ...$changes, 'id' => $id];
    }

    /**
     * Remove a record behind the platform's back.
     */
    public function forget(string $id): void
    {
        unset($this->records[$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return array_values($this->records);
    }

    public function writes(): int
    {
        return $this->writes;
    }

    public function loseTheNextAnswer(): void
    {
        $this->loseNextAnswer = true;
    }

    /**
     * The next write never arrives: the connection dies before the zone is
     * touched. To the caller this is indistinguishable from
     * {@see self::loseTheNextAnswer()}, which is the point — the platform has
     * to settle both by looking.
     */
    public function failTheNextWrite(): void
    {
        $this->failNextWrite = true;
    }

    public function answer(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = substr($path, strlen((string) parse_url(self::BASE, PHP_URL_PATH)));

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($path === '/zones' && $request->method() === 'GET') {
            $name = (string) ($query['name'] ?? '');

            return $this->ok($name === '' || $name === $this->zoneName
                ? [['id' => self::ZONE_ID, 'name' => $this->zoneName, 'name_servers' => []]]
                : []);
        }

        $prefix = '/zones/'.self::ZONE_ID.'/dns_records';

        if (! str_starts_with($path, $prefix)) {
            return Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'No route']]], 404);
        }

        $id = ltrim(substr($path, strlen($prefix)), '/');

        if ($request->method() === 'GET') {
            return $this->list($query);
        }

        $this->writes++;

        if ($this->failNextWrite) {
            $this->failNextWrite = false;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        $response = match ($request->method()) {
            'POST' => $this->create($request->data()),
            'PUT' => $this->replace($id, $request->data()),
            'DELETE' => $this->remove($id),
            default => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Method not allowed']]], 405),
        };

        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;

            // Applied, and then the connection died: the caller learns nothing.
            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query): mixed
    {
        $matching = array_values(array_filter($this->records, static function (array $row) use ($query): bool {
            if (isset($query['type']) && $row['type'] !== $query['type']) {
                return false;
            }

            return ! isset($query['name']) || $row['name'] === $query['name'];
        }));

        $perPage = max(1, (int) ($query['per_page'] ?? 20));
        $page = max(1, (int) ($query['page'] ?? 1));
        $totalPages = max(1, (int) ceil(count($matching) / $perPage));

        return Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => array_slice($matching, ($page - 1) * $perPage, $perPage),
            'result_info' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => count(array_slice($matching, ($page - 1) * $perPage, $perPage)),
                'total_count' => count($matching),
                'total_pages' => $totalPages,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function create(array $payload): mixed
    {
        $id = 'rec-'.$this->nextId++;
        $this->records[$id] = [...$payload, 'id' => $id];

        return $this->ok($this->records[$id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replace(string $id, array $payload): mixed
    {
        if (! isset($this->records[$id])) {
            return $this->unknown();
        }

        $this->records[$id] = [...$payload, 'id' => $id];

        return $this->ok($this->records[$id]);
    }

    private function remove(string $id): mixed
    {
        if (! isset($this->records[$id])) {
            return $this->unknown();
        }

        unset($this->records[$id]);

        return $this->ok(['id' => $id]);
    }

    /**
     * Invented, not derived — see the class docblock.
     */
    private function unknown(): mixed
    {
        return Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']], 'result' => null], 404);
    }

    /**
     * @param  array<mixed>  $result
     */
    private function ok(array $result): mixed
    {
        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result]);
    }
}
