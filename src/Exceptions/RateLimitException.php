<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Raised on HTTP 429, typically produced by a reverse proxy in front of the cluster.
 */
final class RateLimitException extends ApiException
{
    private ?int $retryAfter = null;

    public static function fromHttpResponse(string $operation, Response $response): self
    {
        $payload = $response->json();
        $exception = self::fromResponse($operation, 429, $response->reason(), is_array($payload) ? $payload : []);
        $header = trim($response->header('Retry-After'));

        if ($header !== '') {
            if (is_numeric($header)) {
                $exception->retryAfter = max(0, (int) $header);
            } else {
                $timestamp = strtotime($header);
                $exception->retryAfter = $timestamp === false ? null : max(0, $timestamp - time());
            }
        }

        return $exception;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
