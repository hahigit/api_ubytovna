<?php

declare(strict_types=1);

namespace Planovac\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Falešný HTTP handler pro Guzzle: odpovědi se určují podle "METODA URL-prefix",
 * takže nezáleží na pořadí paralelních požadavků. Nenamapovaná URL = služba neběží.
 */
final class FakeHttp
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $routes = [];
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function on(string $method, string $urlPrefix, mixed $result): self
    {
        array_unshift($this->routes, ["$method $urlPrefix", $result]);
        return $this;
    }

    public function json(string $method, string $urlPrefix, mixed $data, int $status = 200): self
    {
        return $this->on($method, $urlPrefix, self::response($data, $status));
    }

    public function timeout(string $method, string $urlPrefix): self
    {
        return $this->on($method, $urlPrefix, fn (RequestInterface $r) => new ConnectException('cURL error 28: Operation timed out', $r, null, ['errno' => 28]));
    }

    public static function response(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create($this)]);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;
        $key = $request->getMethod() . ' ' . $request->getUri();
        foreach ($this->routes as [$prefix, $result]) {
            if (str_starts_with($key, $prefix)) {
                if ($result instanceof \Closure) {
                    $result = $result($request);
                }
                return $result instanceof ResponseInterface ? Create::promiseFor($result) : Create::rejectionFor($result);
            }
        }
        return Create::rejectionFor(new ConnectException('cURL error 7: Connection refused', $request, null, ['errno' => 7]));
    }

    /** @return list<RequestInterface> */
    public function requestsTo(string $urlPrefix): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_starts_with((string) $r->getUri(), $urlPrefix)));
    }
}
