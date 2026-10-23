<?php

declare(strict_types=1);

namespace Planovac\Tests;

use Planovac\ApiException;
use Planovac\Tests\Support\TestCase;

final class OfferAggregatorTest extends TestCase
{
    private const FAR = ['city' => 'Praha', 'date_from' => '2027-01-10', 'date_to' => '2027-01-12', 'people' => 2];
    private const NEAR = ['city' => 'Praha', 'date_from' => '2026-11-05', 'date_to' => '2026-11-07', 'people' => 2];

    public function testOffersFromMoreProvidersStaySeparateAndFailuresAreReported(): void
    {
        $this->fake
            ->json('POST', self::ACC_A . '/search-hotels', self::hotelsResponse('HOTEL-PRG-01', 'Praha', 2100, 85.0))
            ->timeout('POST', self::ACC_B . '/search-hotels')
            ->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        // atrakce-b neběží (connection refused)

        $offer = $this->aggregator()->offer(self::FAR, self::TODAY);

        self::assertSame(['ubytovani-a', 'ubytovani-b'], array_column($offer['accommodation'], 'source'));
        [$accA, $accB] = $offer['accommodation'];
        self::assertSame('ok', $accA['status']);
        self::assertSame('ubytovani-a', $accA['hotels'][0]['source']);
        self::assertSame('HOTEL-PRG-01', $accA['hotels'][0]['hotel_id']);
        self::assertSame('error', $accB['status']);
        self::assertSame([], $accB['hotels']);

        [$attrA, $attrB] = $offer['attractions'];
        self::assertSame('ok', $attrA['status']);
        self::assertCount(2, $attrA['attractions']);
        self::assertSame('atrakce-a', $attrA['attractions'][1]['source']);
        self::assertSame(700, $attrA['attractions'][1]['price_for_group']['price_czk']);
        self::assertSame('error', $attrB['status']);

        $failures = array_column($offer['partial_failures'], 'reason', 'source');
        self::assertSame(['ubytovani-b', 'atrakce-b'], array_keys($failures));
        self::assertStringContainsString('časový limit', $failures['ubytovani-b']);
        self::assertStringContainsString('nedostupná', $failures['atrakce-b']);
        self::assertSame(2, $offer['summary']['providers_failed']);

        // Termín mimo 16denní předpověď: předpověď se nevolá, výpadek geocodingu není chyba.
        self::assertFalse($offer['weather']['available']);
        self::assertStringContainsString('16 dní', $offer['weather']['reason']);
        self::assertSame([], $this->fake->requestsTo(self::FORECAST));
    }

    public function testProvidersAreCalledInParallelWithCorrectRequests(): void
    {
        $this->fake
            ->json('POST', self::ACC_A . '/search-hotels', self::hotelsResponse('H1', 'Praha', 1000, 40.0))
            ->json('POST', self::ACC_B . '/search-hotels', self::hotelsResponse('H2', 'Praha', 2000, 80.0))
            ->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions())
            ->json('GET', self::ATTR_B . '/attractions/date/', ['attractions' => []]);

        $offer = $this->aggregator()->offer(self::FAR, self::TODAY);

        $search = $this->fake->requestsTo(self::ACC_B)[0];
        self::assertSame(self::FAR, json_decode((string) $search->getBody(), true));
        self::assertSame('city=Praha', $this->fake->requestsTo(self::ATTR_A)[0]->getUri()->getQuery());
        self::assertSame(['H1', 'H2'], [$offer['accommodation'][0]['hotels'][0]['hotel_id'], $offer['accommodation'][1]['hotels'][0]['hotel_id']]);
        self::assertSame(2, $offer['summary']['hotels']);
        self::assertSame([], $offer['partial_failures']);
    }

    public function testEmptyListAndUnofferedCityAreNotFailures(): void
    {
        $this->fake
            ->json('POST', self::ACC_A . '/search-hotels', self::hotelsResponse('HOTEL-PRG-01', 'Praha', 2100, 85.0))
            ->json('POST', self::ACC_B . '/search-hotels', self::cityNotFound(), 404)
            ->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions())
            ->json('GET', self::ATTR_B . '/attractions/date/', ['service' => 'Lokální atrakce API', 'version' => '1.0', 'attractions' => []]);

        $offer = $this->aggregator()->offer(self::FAR, self::TODAY);

        self::assertSame([], $offer['partial_failures']);
        self::assertSame('empty', $offer['accommodation'][1]['status']);
        self::assertStringContainsString('nenabízí', $offer['accommodation'][1]['message']);
        self::assertSame('empty', $offer['attractions'][1]['status']);
        self::assertSame(0, $offer['summary']['providers_failed']);
    }

    public function testInvalidForeignDataIsNotSilentlyIgnored(): void
    {
        $data = self::pragueAttractions();
        $data['attractions'][] = ['id' => 'BROKEN', 'name' => 'Bez ceny', 'location' => 'Praha'];
        $this->fake
            ->json('POST', self::ACC_A . '/search-hotels', ['nothing' => true])
            ->json('POST', self::ACC_B . '/search-hotels', self::cityNotFound(), 404)
            ->json('GET', self::ATTR_A . '/attractions/date/', $data)
            ->on('GET', self::ATTR_B . '/attractions/date/', new \GuzzleHttp\Psr7\Response(200, [], '<html>oops</html>'));

        $offer = $this->aggregator()->offer(self::FAR, self::TODAY);

        $reasons = array_map(fn ($f) => "{$f['source']}: {$f['reason']}", $offer['partial_failures']);
        self::assertContains("ubytovani-a: Odpověď neobsahuje pole 'hotels'.", $reasons);
        self::assertContains('atrakce-a: Vynecháno 1 atrakcí s neplatným formátem dat.', $reasons);
        self::assertContains('atrakce-b: Odpověď není platný JSON.', $reasons);
        self::assertCount(2, $offer['attractions'][0]['attractions'], 'platné atrakce zůstávají v nabídce');
        self::assertSame(2, $offer['summary']['providers_failed'], 'vynechaná položka není výpadek poskytovatele');
    }

    public function testUpstreamServerErrorIsReported(): void
    {
        $this->fake->json('POST', self::ACC_A . '/search-hotels', ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Interní chyba serveru.']], 500);
        $offer = $this->aggregator()->offer(self::FAR, self::TODAY);
        self::assertSame('HTTP 500: Interní chyba serveru.', $offer['accommodation'][0]['message']);
    }

    public function testWeatherForecastIsReturnedAndCached(): void
    {
        $this->pragueGeocoding();
        $this->fake->json('GET', self::FORECAST, ['daily' => [
            'time' => ['2026-11-05', '2026-11-06', '2026-11-07'],
            'weathercode' => [0, 61, 3],
            'temperature_2m_max' => [12.1, 9.4, 8.0],
            'temperature_2m_min' => [3.2, 4.0, 1.5],
            'precipitation_sum' => [0.0, 5.2, 0.1],
        ]]);

        $first = $this->aggregator()->offer(self::NEAR, self::TODAY)['weather'];
        self::assertTrue($first['available']);
        self::assertFalse($first['cached']);
        self::assertSame('Praha', $first['location']['name']);
        self::assertSame(['Jasno', 'Slabý déšť', 'Zataženo'], array_column($first['days'], 'description'));

        $forecastQuery = [];
        parse_str($this->fake->requestsTo(self::FORECAST)[0]->getUri()->getQuery(), $forecastQuery);
        self::assertSame('2026-11-05', $forecastQuery['start_date']);
        self::assertSame('2026-11-07', $forecastQuery['end_date']);
        self::assertSame('Europe/Prague', $forecastQuery['timezone']);

        $second = $this->aggregator()->offer(self::NEAR, self::TODAY)['weather'];
        self::assertTrue($second['cached']);
        self::assertCount(1, $this->fake->requestsTo(self::FORECAST), 'druhé volání jde z cache');
        self::assertCount(1, $this->fake->requestsTo(self::GEO));
    }

    public function testWeatherPartiallyOutOfRangeIsClamped(): void
    {
        $this->pragueGeocoding();
        $this->fake->json('GET', self::FORECAST, ['daily' => ['time' => ['2026-11-17'], 'weathercode' => [2], 'temperature_2m_max' => [7], 'temperature_2m_min' => [1], 'precipitation_sum' => [0]]]);

        $weather = $this->aggregator()->offer(['city' => 'Praha', 'date_from' => '2026-11-17', 'date_to' => '2026-11-20', 'people' => 1], self::TODAY)['weather'];

        self::assertTrue($weather['partial']);
        parse_str($this->fake->requestsTo(self::FORECAST)[0]->getUri()->getQuery(), $q);
        self::assertSame('2026-11-17', $q['end_date']);
    }

    public function testWeatherFailureDoesNotBreakOffer(): void
    {
        $this->fake
            ->json('POST', self::ACC_A . '/search-hotels', self::hotelsResponse('HOTEL-PRG-01', 'Praha', 2100, 85.0))
            ->json('GET', self::GEO, ['error' => true, 'reason' => 'boom'], 500);

        $offer = $this->aggregator()->offer(self::NEAR, self::TODAY);

        self::assertNull($offer['weather']);
        self::assertSame('open-meteo', $offer['partial_failures'][array_key_last($offer['partial_failures'])]['source']);
        self::assertSame('ok', $offer['accommodation'][0]['status']);
    }

    public function testOutOfRangeWeatherStillGeocodes(): void
    {
        $this->pragueGeocoding();
        $weather = $this->aggregator()->offer(self::FAR, self::TODAY)['weather'];
        self::assertFalse($weather['available']);
        self::assertSame('Praha', $weather['location']['name']);
        self::assertSame([], $this->fake->requestsTo(self::FORECAST));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function unknownCityDates(): iterable
    {
        yield 'v rozsahu předpovědi' => [self::NEAR];
        yield 'mimo rozsah předpovědi' => [self::FAR];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownCityDates')]
    public function testUnknownCityIs404(array $dates): void
    {
        $this->fake
            ->json('POST', self::ACC_A . '/search-hotels', self::cityNotFound(), 404)
            ->json('POST', self::ACC_B . '/search-hotels', self::cityNotFound(), 404)
            ->json('GET', self::ATTR_A . '/attractions/date/', ['attractions' => []])
            ->json('GET', self::ATTR_B . '/attractions/date/', ['attractions' => []])
            ->json('GET', self::GEO, ['generationtime_ms' => 0.1]);

        try {
            $this->aggregator()->offer(['city' => 'Xyzzy'] + $dates, self::TODAY);
            self::fail('Očekávána výjimka');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('CITY_NOT_FOUND', $e->errorCode);
        }
    }
}
