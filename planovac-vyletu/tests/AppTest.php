<?php

declare(strict_types=1);

namespace Planovac\Tests;

use Planovac\Bootstrap;
use Planovac\Tests\Support\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** HTTP testy přes Slim (bez spouštění serveru). */
final class AppTest extends TestCase
{
    /** @return array{0: int, 1: array<mixed>, 2: ResponseInterface} */
    private function call(string $method, string $uri, ?string $body = null): array
    {
        $app = Bootstrap::app($this->config(), $this->fake->client(), fn () => self::TODAY);
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream($body));
        }
        $response = $app->handle($request);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $response];
    }

    public function testRootReportsLiveProviderStatus(): void
    {
        $this->fake
            ->json('GET', self::ACC_A . '/', ['service' => 'Rezervace ubytování API', 'version' => '1.0', 'localities' => ['Brno', 'Praha']])
            ->json('GET', self::ATTR_A . '/', ['service' => 'Lokální atrakce API', 'version' => '1.0', 'localities' => ['Praha']])
            ->timeout('GET', self::ATTR_B . '/');

        [$status, $body, $response] = $this->call('GET', '/');

        self::assertSame(200, $status);
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Plánovač výletů API', $body['service']);
        $providers = array_column($body['providers'], null, 'id');
        self::assertSame('available', $providers['ubytovani-a']['status']);
        self::assertSame(['Brno', 'Praha'], $providers['ubytovani-a']['localities']);
        self::assertSame('unavailable', $providers['ubytovani-b']['status']);
        self::assertSame('unavailable', $providers['atrakce-b']['status']);
        self::assertSame(['Brno', 'Praha'], $body['localities']);
    }

    public function testLocalityWithUrlEncodedCity(): void
    {
        $this->fake->json('POST', self::ACC_A . '/search-hotels', self::hotelsResponse('HOTEL-KRU-01', 'Český Krumlov', 1850, 75.0));

        [$status, $body] = $this->call('GET', '/locality/%C4%8Cesk%C3%BD%20Krumlov/2027-01-10/2027-01-12?people=2');

        self::assertSame(200, $status);
        self::assertSame('Český Krumlov', $body['query']['city']);
        self::assertSame(2, $body['query']['people']);
        self::assertSame('Český Krumlov', json_decode((string) $this->fake->requestsTo(self::ACC_A)[0]->getBody(), true)['city']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function badLocalityProvider(): iterable
    {
        yield 'neplatné datum' => ['/locality/Praha/2026-02-30/2026-03-02', 'date_from'];
        yield 'odjezd před příjezdem' => ['/locality/Praha/2026-11-10/2026-11-08', 'date_to'];
        yield 'stejný den' => ['/locality/Praha/2026-11-10/2026-11-10', 'date_to'];
        yield 'minulost' => ['/locality/Praha/2026-10-01/2026-10-03', 'minulosti'];
        yield 'people 0' => ['/locality/Praha/2026-11-10/2026-11-12?people=0', 'people'];
        yield 'people text' => ['/locality/Praha/2026-11-10/2026-11-12?people=abc', 'people'];
        yield 'neplatné město' => ['/locality/%3Cscript%3E/2026-11-10/2026-11-12', 'města'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLocalityProvider')]
    public function testLocalityValidation(string $uri, string $expected): void
    {
        [$status, $body] = $this->call('GET', $uri);
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertStringContainsString($expected, $body['error']['message']);
    }

    public function testPlanTripAndGetOrder(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $payload = json_encode([
            'city' => 'Praha', 'date_from' => '2026-11-05', 'date_to' => '2026-11-06', 'people' => 1,
            'customer' => ['name' => 'Eva', 'email' => 'eva@example.cz'],
            'attractions' => [['source' => 'atrakce-a', 'id' => 'ATTR-PRG-02']],
        ]);

        [$status, $order] = $this->call('POST', '/plan-trip', $payload);
        self::assertSame(201, $status);
        self::assertSame('confirmed', $order['status']);

        [$status, $found] = $this->call('GET', '/orders/' . $order['order_id']);
        self::assertSame(200, $status);
        self::assertSame($order, $found);
    }

    public function testPlanTripInvalidJson(): void
    {
        [$status, $body] = $this->call('POST', '/plan-trip', '{"city": ');
        self::assertSame(400, $status);
        self::assertSame('INVALID_JSON', $body['error']['code']);
    }

    public function testNotFoundResponses(): void
    {
        [$status, $body] = $this->call('GET', '/orders/ORD-NIC');
        self::assertSame(404, $status);
        self::assertSame('ORDER_NOT_FOUND', $body['error']['code']);

        [$status, $body] = $this->call('GET', '/neexistuje');
        self::assertSame(404, $status);
        self::assertSame('NOT_FOUND', $body['error']['code']);

        [$status, $body] = $this->call('DELETE', '/plan-trip');
        self::assertSame(405, $status);
        self::assertSame('METHOD_NOT_ALLOWED', $body['error']['code']);
    }
}
