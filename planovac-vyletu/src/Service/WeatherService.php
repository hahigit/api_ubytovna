<?php

declare(strict_types=1);

namespace Planovac\Service;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Planovac\Dates;

final class WeatherService
{
    public const NOT_FOUND = 'city_not_found';

    private const WMO = [
        0 => 'Jasno', 1 => 'Převážně jasno', 2 => 'Polojasno', 3 => 'Zataženo',
        45 => 'Mlha', 48 => 'Mlha s námrazou',
        51 => 'Slabé mrholení', 53 => 'Mrholení', 55 => 'Silné mrholení',
        56 => 'Mrznoucí mrholení', 57 => 'Silné mrznoucí mrholení',
        61 => 'Slabý déšť', 63 => 'Déšť', 65 => 'Silný déšť',
        66 => 'Mrznoucí déšť', 67 => 'Silný mrznoucí déšť',
        71 => 'Slabé sněžení', 73 => 'Sněžení', 75 => 'Silné sněžení', 77 => 'Sněhová zrna',
        80 => 'Slabé přeháňky', 81 => 'Přeháňky', 82 => 'Silné přeháňky',
        85 => 'Sněhové přeháňky', 86 => 'Silné sněhové přeháňky',
        95 => 'Bouřka', 96 => 'Bouřka s krupobitím', 99 => 'Silná bouřka s krupobitím',
    ];

    public function __construct(
        private readonly HttpJson $http,
        private readonly FileCache $cache,
        private readonly array $config,
    ) {
    }

    public function forecastAsync(string $city, string $dateFrom, string $dateTo, string $today): PromiseInterface
    {
        $lastForecastDay = Dates::addDays($today, $this->config['forecast_days'] - 1);
        $start = max($dateFrom, $today);
        $end = min($dateTo, $lastForecastDay);
        $outOfRange = $dateFrom > $lastForecastDay;
        $outOfRangeResult = [
            'available' => false,
            'reason' => sprintf(
                'Předpověď počasí je k dispozici jen %d dní dopředu (do %s). Pro termín %s – %s ji zatím nelze zobrazit.',
                $this->config['forecast_days'], $lastForecastDay, $dateFrom, $dateTo,
            ),
        ];

        $promise = $this->geocodeAsync($city)->then(function (?array $place) use ($city, $start, $end, $dateTo, $outOfRange, $outOfRangeResult) {
            if ($place === null) {
                return ['available' => false, 'reason' => "Město '$city' se nepodařilo najít v geocodingu Open-Meteo.", 'code' => self::NOT_FOUND];
            }
            if ($outOfRange) {
                return $outOfRangeResult + ['location' => $place];
            }
            return $this->dailyAsync($place, $start, $end)->then(function (array $result) use ($place, $dateTo, $end) {
                $weather = [
                    'available' => true,
                    'provider' => 'Open-Meteo',
                    'location' => $place,
                    'days' => $result['days'],
                    'units' => ['temperature' => '°C', 'precipitation' => 'mm'],
                    'cached' => $result['cached'],
                ];
                if ($end < $dateTo) {
                    $weather['partial'] = true;
                    $weather['note'] = "Předpověď pokrývá jen dny do $end, zbytek termínu je mimo rozsah předpovědi.";
                }
                return $weather;
            });
        });

        return $outOfRange ? $promise->otherwise(fn () => $outOfRangeResult) : $promise;
    }

    private function geocodeAsync(string $city): PromiseInterface
    {
        $query = ['name' => $city, 'count' => 1, 'language' => 'cs', 'format' => 'json'];
        return $this->cachedGet($this->config['geocoding_url'], $query)->then(function (array $r) {
            $data = $r['data'];
            $first = $data['results'][0] ?? null;
            if (!is_array($first) || !isset($first['latitude'], $first['longitude'])) {
                return null;
            }
            return [
                'name' => (string) ($first['name'] ?? ''),
                'country' => (string) ($first['country'] ?? ''),
                'latitude' => (float) $first['latitude'],
                'longitude' => (float) $first['longitude'],
            ];
        });
    }

    private function dailyAsync(array $place, string $start, string $end): PromiseInterface
    {
        $query = [
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'daily' => 'weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => 'Europe/Prague',
            'start_date' => $start,
            'end_date' => $end,
        ];
        return $this->cachedGet($this->config['forecast_url'], $query)->then(function (array $r) {
            $daily = $r['data']['daily'] ?? null;
            if (!is_array($daily) || !is_array($daily['time'] ?? null)) {
                throw new UpstreamException(UpstreamException::INVALID_RESPONSE, 'Open-Meteo vrátilo neočekávaný formát předpovědi.');
            }
            $days = [];
            foreach ($daily['time'] as $i => $date) {
                $code = $daily['weathercode'][$i] ?? null;
                $days[] = [
                    'date' => $date,
                    'weathercode' => $code,
                    'description' => $code !== null ? (self::WMO[(int) $code] ?? 'Neznámý stav') : null,
                    'temperature_max' => $daily['temperature_2m_max'][$i] ?? null,
                    'temperature_min' => $daily['temperature_2m_min'][$i] ?? null,
                    'precipitation_sum' => $daily['precipitation_sum'][$i] ?? null,
                ];
            }
            return ['days' => $days, 'cached' => $r['cached']];
        });
    }

    private function cachedGet(string $url, array $query): PromiseInterface
    {
        $fullUrl = $url . '?' . http_build_query($query);
        $cached = $this->cache->get($fullUrl);
        if (is_array($cached)) {
            return Create::promiseFor(['data' => $cached, 'cached' => true]);
        }
        return $this->http->requestAsync('GET', $fullUrl, (float) $this->config['timeout'])->then(function (array $data) use ($fullUrl) {
            if (isset($data['error']) && $data['error'] === true) {
                throw new UpstreamException(UpstreamException::INVALID_RESPONSE, 'Open-Meteo: ' . ($data['reason'] ?? 'neznámá chyba'));
            }
            $this->cache->set($fullUrl, $data);
            return ['data' => $data, 'cached' => false];
        });
    }
}
