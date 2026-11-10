<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Http;

use Beziworld\Proxmox\Exceptions\ApiException;
use Beziworld\Proxmox\Exceptions\AuthenticationException;
use Beziworld\Proxmox\Exceptions\ConnectionFailed;
use Beziworld\Proxmox\Exceptions\PermissionDenied;
use Beziworld\Proxmox\Exceptions\RateLimitException;
use Beziworld\Proxmox\ProxmoxConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * HTTP transport of the Proxmox VE API (https://node:8006/api2/json).
 *
 * API tokens are sent in the Authorization header ("PVEAPIToken=...") and
 * need no CSRF token. With a username and password a ticket is obtained
 * from /access/ticket, cached, and sent as the PVEAuthCookie cookie, with
 * the CSRFPreventionToken header on POST, PUT and DELETE. An expired ticket
 * (HTTP 401) is renewed once. GET requests are repeated on connection
 * errors and HTTP 5xx; state-changing requests are sent exactly once.
 */
class ProxmoxApi
{
    /** @var array{ticket: string, csrf: string}|null */
    private ?array $ticket = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ProxmoxConfig $config,
        private readonly Cache $cache,
        private readonly string $cachePrefix = 'proxmox:ticket:',
        private readonly int $ticketTtl = 6600,
    ) {}

    public function config(): ProxmoxConfig
    {
        return $this->config;
    }

    public function withConfig(ProxmoxConfig $config): self
    {
        return new self($this->http, $config, $this->cache, $this->cachePrefix, $this->ticketTtl);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function post(string $path, array $parameters = []): mixed
    {
        return $this->request('POST', $path, $parameters);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function put(string $path, array $parameters = []): mixed
    {
        return $this->request('PUT', $path, $parameters);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function delete(string $path, array $parameters = []): mixed
    {
        return $this->request('DELETE', $path, $parameters);
    }

    /**
     * Sends a request and returns the "data" member of the response.
     *
     * @param string $path Path below /api2/json, e.g. "/nodes/pve1/qemu".
     * @param array<string, scalar|null> $parameters Query string for GET and DELETE, form body otherwise.
     *
     * @throws AuthenticationException
     * @throws PermissionDenied
     * @throws RateLimitException
     * @throws ApiException
     * @throws ConnectionFailed
     */
    public function request(string $method, string $path, array $parameters = []): mixed
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $operation = $method . ' ' . $path;
        $parameters = self::normalize($parameters);
        $attempts = $method === 'GET' ? $this->config->retries + 1 : 1;
        $renewed = false;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->send($method, $path, $parameters);
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->pause($attempt);

                    continue;
                }

                throw ConnectionFailed::during($operation, $this->config->url, $exception);
            }

            if ($response->status() === 401 && ! $this->config->usesToken() && ! $renewed) {
                $renewed = true;
                $this->forgetTicket();
                $attempt--;

                continue;
            }

            if ($response->serverError() && $attempt < $attempts) {
                $this->pause($attempt);

                continue;
            }

            return $this->decode($operation, $response);
        }
    }

    /**
     * Obtains a fresh ticket explicitly; normally done lazily on the first request.
     */
    public function login(): void
    {
        $this->forgetTicket();
        $this->ticket();
    }

    public function forgetTicket(): void
    {
        $this->ticket = null;
        $this->cache->forget($this->cacheKey());
    }

    /**
     * @param array<string, string> $parameters
     */
    private function send(string $method, string $path, array $parameters): Response
    {
        $request = $this->base();
        $url = $this->config->apiUrl() . $path;

        if ($this->config->usesToken()) {
            $request = $request->withHeaders(['Authorization' => (string) $this->config->tokenHeader()]);
        } else {
            $ticket = $this->ticket();
            $request = $request->withHeaders(['Cookie' => 'PVEAuthCookie=' . rawurlencode($ticket['ticket'])]);

            if ($method !== 'GET') {
                $request = $request->withHeaders(['CSRFPreventionToken' => $ticket['csrf']]);
            }
        }

        if ($method === 'GET' || $method === 'DELETE') {
            return $request->send($method, $url, $parameters === [] ? [] : ['query' => $parameters]);
        }

        return $request->asForm()->send($method, $url, ['form_params' => $parameters]);
    }

    /**
     * @return array{ticket: string, csrf: string}
     */
    private function ticket(): array
    {
        if ($this->ticket !== null) {
            return $this->ticket;
        }

        $cached = $this->cache->get($this->cacheKey());

        if (is_array($cached) && is_string($cached['ticket'] ?? null) && is_string($cached['csrf'] ?? null)) {
            return $this->ticket = ['ticket' => $cached['ticket'], 'csrf' => $cached['csrf']];
        }

        $operation = 'POST /access/ticket';

        try {
            $response = $this->base()->asForm()->post($this->config->apiUrl() . '/access/ticket', [
                'username' => (string) $this->config->username,
                'password' => (string) $this->config->password,
            ]);
        } catch (ConnectionException $exception) {
            throw ConnectionFailed::during($operation, $this->config->url, $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw AuthenticationException::fromResponse($operation, $response->status(), $response->reason(), ['message' => 'Login rejected for ' . $this->config->username]);
        }

        $data = $this->decode($operation, $response);
        $ticket = is_array($data) && is_string($data['ticket'] ?? null) ? $data['ticket'] : null;
        $csrf = is_array($data) && is_string($data['CSRFPreventionToken'] ?? null) ? $data['CSRFPreventionToken'] : null;

        if ($ticket === null || $csrf === null) {
            throw AuthenticationException::fromResponse($operation, $response->status(), 'No ticket in the response (two-factor authentication is not supported, use an API token)', []);
        }

        $this->ticket = ['ticket' => $ticket, 'csrf' => $csrf];
        $this->cache->put($this->cacheKey(), $this->ticket, $this->ticketTtl);

        return $this->ticket;
    }

    private function decode(string $operation, Response $response): mixed
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($response->successful()) {
            if (! array_key_exists('data', $payload) && trim($response->body()) !== '') {
                throw ApiException::unexpected($operation, $response->status());
            }

            return $payload['data'] ?? null;
        }

        throw match ($response->status()) {
            401 => AuthenticationException::fromResponse($operation, 401, $response->reason(), $payload),
            403 => PermissionDenied::fromResponse($operation, 403, $response->reason(), $payload),
            429 => RateLimitException::fromHttpResponse($operation, $response),
            default => ApiException::fromResponse($operation, $response->status(), $response->reason(), $payload),
        };
    }

    /**
     * Converts booleans into the 1/0 integers Proxmox expects and drops null values.
     *
     * @param array<string, scalar|null> $parameters
     * @return array<string, string>
     */
    private static function normalize(array $parameters): array
    {
        $normalized = [];

        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $normalized;
    }

    private function base(): PendingRequest
    {
        return $this->http
            ->createPendingRequest()
            ->acceptJson()
            ->withUserAgent('beziworld-laravel-proxmox/1.0')
            ->withOptions(['verify' => $this->config->verifySsl])
            ->timeout($this->config->timeout)
            ->connectTimeout($this->config->connectTimeout);
    }

    private function pause(int $attempt): void
    {
        if ($this->config->retryDelay > 0) {
            Sleep::usleep($this->config->retryDelay * 1000 * $attempt);
        }
    }

    private function cacheKey(): string
    {
        return $this->cachePrefix . hash('sha256', implode('|', [$this->config->url, $this->config->username]));
    }
}
