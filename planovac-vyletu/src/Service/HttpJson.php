<?php

declare(strict_types=1);

namespace Planovac\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Asynchronní JSON volání přes Guzzle. Promise se splní dekódovaným polem,
 * nebo se zamítne s UpstreamException (vždy s českým důvodem).
 */
final class HttpJson
{
    public function __construct(private readonly ClientInterface $http)
    {
    }

    /** @param array<string, mixed> $options */
    public function requestAsync(string $method, string $url, float $timeout, array $options = []): PromiseInterface
    {
        $options += [
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, 2.0),
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => true,
        ];

        return $this->http->requestAsync($method, $url, $options)->then(
            fn (ResponseInterface $response) => self::decode($response),
            function (\Throwable $e) use ($timeout): never {
                throw self::normalize($e, $timeout);
            },
        );
    }

    /** @return array<mixed> */
    public static function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UpstreamException(UpstreamException::INVALID_RESPONSE, 'Odpověď není platný JSON.', $response->getStatusCode());
        }
        if (!is_array($data)) {
            throw new UpstreamException(UpstreamException::INVALID_RESPONSE, 'Odpověď není JSON objekt.', $response->getStatusCode());
        }
        return $data;
    }

    public static function normalize(\Throwable $e, float $timeout): UpstreamException
    {
        if ($e instanceof UpstreamException) {
            return $e;
        }
        if ($e instanceof RequestException || $e instanceof ConnectException) {
            $errno = $e->getHandlerContext()['errno'] ?? null;
            if ($errno === 28 || stripos($e->getMessage(), 'timed out') !== false) {
                return new UpstreamException(UpstreamException::TIMEOUT, sprintf('Vypršel časový limit %s s.', rtrim(rtrim(number_format($timeout, 1, '.', ''), '0'), '.')), previous: $e);
            }
        }
        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $code = null;
            $message = null;
            try {
                $data = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
                $code = is_string($data['error']['code'] ?? null) ? $data['error']['code'] : null;
                $message = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : null;
            } catch (\JsonException) {
            }
            return new UpstreamException(
                UpstreamException::HTTP_ERROR,
                $message !== null ? "HTTP $status: $message" : "Poskytovatel odpověděl chybou HTTP $status.",
                $status,
                $code,
                $e,
            );
        }
        if ($e instanceof ConnectException) {
            return new UpstreamException(UpstreamException::UNREACHABLE, 'Služba je nedostupná (nelze se připojit).', previous: $e);
        }
        return new UpstreamException(UpstreamException::UNREACHABLE, 'Chyba komunikace: ' . $e->getMessage(), previous: $e);
    }
}
