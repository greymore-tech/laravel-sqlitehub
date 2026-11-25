<?php

namespace SQLiteHub\Laravel\Hrana;

use Closure;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;

/**
 * Sends Hrana pipelines (POST /v3/pipeline, JSON) to sqld or the SQLiteHub edge.
 *
 * Stateless: the caller keeps the baton (and base_url) of an open stream.
 */
class HranaClient
{
    public const PIPELINE_PATH = '/v3/pipeline';

    public readonly string $url;

    /** One Guzzle handler for all requests, so curl keeps the HTTPS connection alive between statements. */
    private mixed $handler = null;

    /** @param  Closure(): Factory  $http  resolved per request, so Http::fake() applies */
    public function __construct(
        private readonly Closure $http,
        string $url,
        #[\SensitiveParameter] private readonly ?string $token = null,
        private readonly ?string $hostHeader = null,
        private readonly float $timeout = 30,
        private readonly float $connectTimeout = 10,
    ) {
        $this->url = self::normalizeUrl($url);
    }

    /**
     * libsql://host and wss://host become https://host, ws://host becomes
     * http://host; http(s):// are kept. A trailing slash is dropped.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^([a-z][a-z0-9+.-]*)://([^/?\#]+)(/[^?\#]*)?$#i', $url, $m)) {
            throw new InvalidArgumentException('Set the SQLiteHub URL (SQLITEHUB_URL) to libsql://<database>.<region>.db.sqlitehub.in or https://…');
        }

        $scheme = match (strtolower($m[1])) {
            'libsql', 'https', 'wss' => 'https',
            'http', 'ws' => 'http',
            default => throw new InvalidArgumentException("Unsupported SQLiteHub URL scheme [{$m[1]}]: use libsql:// or https://."),
        };

        return $scheme.'://'.$m[2].rtrim($m[3] ?? '', '/');
    }

    /**
     * Run one pipeline.
     *
     * @param  list<array<string, mixed>>  $requests
     * @param  bool  $idempotent  retry once on a connection error (reads outside a transaction only)
     * @return array{baton: ?string, base_url: ?string, results: list<array<string, mixed>>}
     *
     * @throws HranaException
     */
    public function pipeline(array $requests, ?string $baton = null, ?string $baseUrl = null, bool $idempotent = false): array
    {
        $url = rtrim($baseUrl ?? $this->url, '/').self::PIPELINE_PATH;
        $attempts = $idempotent ? 2 : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                // Unescaped slashes: sqld can't read escaped base64 (blob) strings.
                $response = $this->request()
                    ->withBody(json_encode(['baton' => $baton, 'requests' => $requests], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))
                    ->post($url);
                break;
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    throw new HranaException("Could not reach SQLiteHub at {$this->url}: {$e->getMessage()}", 'CONNECTION_FAILED', null, $e);
                }
            }
        }

        if (! $response->successful()) {
            throw HranaException::fromHttp($response->status(), $response->body());
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['results']) || ! is_array($body['results']) || count($body['results']) !== count($requests)) {
            throw new HranaException('SQLiteHub sent an invalid pipeline response: '.mb_strimwidth($response->body(), 0, 200, '…'), 'INVALID_RESPONSE', $response->status());
        }

        return [
            'baton' => $body['baton'] ?? null,
            'base_url' => $body['base_url'] ?? null,
            'results' => array_values($body['results']),
        ];
    }

    private function request(): PendingRequest
    {
        $request = ($this->http)()
            ->setHandler($this->handler ??= Utils::chooseHandler())
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        if ($this->token !== null && $this->token !== '') {
            $request->withToken($this->token);
        }

        if ($this->hostHeader !== null && $this->hostHeader !== '') {
            $request->withHeaders(['Host' => $this->hostHeader]);
        }

        return $request;
    }
}
