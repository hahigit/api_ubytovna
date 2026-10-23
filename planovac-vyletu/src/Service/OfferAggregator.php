<?php

declare(strict_types=1);

namespace Planovac\Service;

use GuzzleHttp\Promise\Utils;
use Planovac\ApiException;
use Planovac\Dates;
use Planovac\PriceCalculator;
use Planovac\Provider;
use Planovac\ProviderRegistry;

/**
 * Souhrnná nabídka pro lokalitu a termín. Všechna API (ubytování, atrakce, počasí)
 * se volají paralelně; výpadek jednoho poskytovatele nezastaví ostatní.
 */
final class OfferAggregator
{
    public const WEATHER_SOURCE = 'open-meteo';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderGateway $gateway,
        private readonly WeatherService $weather,
    ) {
    }

    /**
     * @param array{city: string, date_from: string, date_to: string, people: int} $q
     * @return array<string, mixed>
     */
    public function offer(array $q, string $today): array
    {
        $accommodationProviders = $this->registry->byType(Provider::TYPE_ACCOMMODATION);
        $attractionProviders = $this->registry->byType(Provider::TYPE_ATTRACTIONS);

        $promises = [];
        foreach ($accommodationProviders as $p) {
            $promises['acc:' . $p->id] = $this->gateway->searchHotelsAsync($p, $q);
        }
        foreach ($attractionProviders as $p) {
            $promises['attr:' . $p->id] = $this->gateway->attractionsAsync($p, $q['city']);
        }
        $promises['weather'] = $this->weather->forecastAsync($q['city'], $q['date_from'], $q['date_to'], $today);

        // Všechny požadavky běží souběžně (curl_multi pod Guzzle), čekáme na všechny.
        $results = Utils::settle($promises)->wait();

        $failures = [];
        $accommodation = [];
        foreach ($accommodationProviders as $p) {
            $accommodation[] = $this->accommodationGroup($p, $results['acc:' . $p->id], $q, $failures);
        }
        $attractions = [];
        foreach ($attractionProviders as $p) {
            $attractions[] = $this->attractionGroup($p, $results['attr:' . $p->id], $q, $failures);
        }

        $weather = null;
        $w = $results['weather'];
        if ($w['state'] === 'fulfilled') {
            $weather = $w['value'];
        } else {
            $failures[] = ['source' => self::WEATHER_SOURCE, 'type' => 'weather', 'reason' => 'Počasí: ' . self::reason($w['reason'])];
        }

        $hotelCount = array_sum(array_map(fn ($g) => count($g['hotels']), $accommodation));
        $attractionCount = array_sum(array_map(fn ($g) => count($g['attractions']), $attractions));
        $providerFailures = array_filter($failures, fn ($f) => $f['type'] !== 'weather' && empty($f['partial']));

        // Neznámé město: nikdo ho nenabízí, nic neselhalo a nezná ho ani geocoding.
        if ($hotelCount === 0 && $attractionCount === 0 && $providerFailures === []
            && ($weather['code'] ?? null) === WeatherService::NOT_FOUND
            && array_filter($accommodation, fn ($g) => !$g['not_offered']) === []) {
            throw new ApiException(404, 'CITY_NOT_FOUND', "Lokalitu '{$q['city']}' nenabízí žádný napojený poskytovatel a nebyla nalezena ani v geocodingu.");
        }
        if ($weather !== null) {
            unset($weather['code']);
        }

        return [
            'service' => 'Plánovač výletů API',
            'query' => $q + ['nights' => Dates::nights($q['date_from'], $q['date_to'])],
            'summary' => [
                'hotels' => $hotelCount,
                'rooms' => array_sum(array_map(fn ($g) => array_sum(array_map(fn ($h) => count($h['available_rooms']), $g['hotels'])), $accommodation)),
                'attractions' => $attractionCount,
                'providers_queried' => count($accommodationProviders) + count($attractionProviders),
                'providers_failed' => count($providerFailures),
            ],
            'accommodation' => array_map(fn ($g) => array_diff_key($g, ['not_offered' => true]), $accommodation),
            'attractions' => $attractions,
            'weather' => $weather,
            'partial_failures' => array_values(array_map(fn ($f) => array_diff_key($f, ['partial' => true]), $failures)),
        ];
    }

    /**
     * @param array{state: string, value?: mixed, reason?: mixed} $result
     * @param list<array<string, mixed>> $failures
     * @return array<string, mixed>
     */
    private function accommodationGroup(Provider $p, array $result, array $q, array &$failures): array
    {
        $group = ['source' => $p->id, 'provider_name' => $p->name, 'status' => 'ok', 'message' => null, 'hotels' => [], 'not_offered' => false];
        if ($result['state'] !== 'fulfilled') {
            $failures[] = ['source' => $p->id, 'type' => $p->type, 'reason' => self::reason($result['reason'])];
            return ['status' => 'error', 'message' => self::reason($result['reason'])] + $group;
        }
        $value = $result['value'];
        $this->reportInvalid($p, $value['invalid'], 'nabídek ubytování', $failures);
        $group['hotels'] = array_map(fn ($h) => ['source' => $p->id] + $h, $value['hotels']);
        if ($value['not_offered']) {
            return ['status' => 'empty', 'not_offered' => true, 'message' => "Poskytovatel lokalitu '{$q['city']}' nenabízí."] + $group;
        }
        if ($group['hotels'] === []) {
            return ['status' => 'empty', 'message' => "Žádný volný pokoj pro {$q['people']} os. v termínu {$q['date_from']} – {$q['date_to']}."] + $group;
        }
        return $group;
    }

    /**
     * @param array{state: string, value?: mixed, reason?: mixed} $result
     * @param list<array<string, mixed>> $failures
     * @return array<string, mixed>
     */
    private function attractionGroup(Provider $p, array $result, array $q, array &$failures): array
    {
        $group = ['source' => $p->id, 'provider_name' => $p->name, 'status' => 'ok', 'message' => null, 'attractions' => []];
        if ($result['state'] !== 'fulfilled') {
            $failures[] = ['source' => $p->id, 'type' => $p->type, 'reason' => self::reason($result['reason'])];
            return ['status' => 'error', 'message' => self::reason($result['reason'])] + $group;
        }
        $value = $result['value'];
        $this->reportInvalid($p, $value['invalid'], 'atrakcí', $failures);
        $group['attractions'] = array_map(
            fn ($a) => ['source' => $p->id] + $a + ['price_for_group' => PriceCalculator::attraction($a['pricing'], $q['people'])],
            $value['attractions'],
        );
        if ($group['attractions'] === []) {
            return ['status' => 'empty', 'message' => "Poskytovatel nemá pro lokalitu '{$q['city']}' žádné atrakce."] + $group;
        }
        return $group;
    }

    /** @param list<array<string, mixed>> $failures */
    private function reportInvalid(Provider $p, int $invalid, string $what, array &$failures): void
    {
        if ($invalid > 0) {
            $failures[] = [
                'source' => $p->id,
                'type' => $p->type,
                'reason' => "Vynecháno $invalid $what s neplatným formátem dat.",
                'partial' => true,
            ];
        }
    }

    public static function reason(mixed $e): string
    {
        return $e instanceof \Throwable ? $e->getMessage() : 'Neznámá chyba.';
    }
}
