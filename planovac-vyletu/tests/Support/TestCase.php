<?php

declare(strict_types=1);

namespace Planovac\Tests\Support;

use Planovac\ProviderRegistry;
use Planovac\Service\FileCache;
use Planovac\Service\HttpJson;
use Planovac\Service\OfferAggregator;
use Planovac\Service\OrderRepository;
use Planovac\Service\ProviderGateway;
use Planovac\Service\TripPlanner;
use Planovac\Service\WeatherService;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const TODAY = '2026-11-02';
    protected const ACC_A = 'http://acc-a.test';
    protected const ACC_B = 'http://acc-b.test';
    protected const ATTR_A = 'http://attr-a.test';
    protected const ATTR_B = 'http://attr-b.test';
    protected const GEO = 'https://geocoding.test/v1/search';
    protected const FORECAST = 'https://forecast.test/v1/forecast';

    protected FakeHttp $fake;
    protected string $tmpDir;

    protected function setUp(): void
    {
        $this->fake = new FakeHttp();
        $this->tmpDir = sys_get_temp_dir() . '/planovac-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
    }

    protected function config(): array
    {
        return [
            'providers' => [
                ['id' => 'ubytovani-a', 'type' => 'accommodation', 'name' => 'Ubytování A', 'base_url' => self::ACC_A, 'timeout' => 1.0],
                ['id' => 'ubytovani-b', 'type' => 'accommodation', 'name' => 'Ubytování B', 'base_url' => self::ACC_B, 'timeout' => 1.0],
                ['id' => 'atrakce-a', 'type' => 'attractions', 'name' => 'Atrakce A', 'base_url' => self::ATTR_A, 'timeout' => 1.0],
                ['id' => 'atrakce-b', 'type' => 'attractions', 'name' => 'Atrakce B', 'base_url' => self::ATTR_B, 'timeout' => 1.0],
            ],
            'status_timeout' => 1.0,
            'weather' => ['geocoding_url' => self::GEO, 'forecast_url' => self::FORECAST, 'timeout' => 1.0, 'cache_ttl' => 1800, 'forecast_days' => 16],
            'cache_dir' => $this->tmpDir . '/cache',
            'data_file' => $this->tmpDir . '/data/orders.json',
            'timezone' => 'Europe/Prague',
        ];
    }

    protected function registry(): ProviderRegistry
    {
        return new ProviderRegistry($this->config()['providers']);
    }

    protected function gateway(): ProviderGateway
    {
        return new ProviderGateway(new HttpJson($this->fake->client()));
    }

    protected function aggregator(): OfferAggregator
    {
        $c = $this->config();
        $weather = new WeatherService(new HttpJson($this->fake->client()), new FileCache($c['cache_dir'], 1800), $c['weather']);
        return new OfferAggregator($this->registry(), $this->gateway(), $weather);
    }

    protected function orders(): OrderRepository
    {
        return new OrderRepository($this->config()['data_file']);
    }

    protected function planner(): TripPlanner
    {
        return new TripPlanner($this->registry(), $this->gateway(), $this->orders());
    }

    protected static function hotelsResponse(string $hotelId, string $city, int $czk, float $eur): array
    {
        return [
            'service' => 'Rezervace ubytování API',
            'version' => '1.0',
            'hotels' => [[
                'hotel_id' => $hotelId,
                'name' => "Hotel $hotelId",
                'location' => $city,
                'available_rooms' => [[
                    'room_id' => 'R1',
                    'room_type' => 'Standard',
                    'capacity_persons' => 2,
                    'availability' => [['date' => '2026-11-05', 'price_czk' => $czk, 'price_eur' => $eur]],
                    'nights' => 1,
                    'total_price_czk' => $czk,
                    'total_price_eur' => $eur,
                ]],
            ]],
        ];
    }

    protected static function pragueAttractions(): array
    {
        return [
            'service' => 'Lokální atrakce API',
            'version' => '1.0',
            'attractions' => [
                ['id' => 'ATTR-PRG-01', 'name' => 'Karlov most', 'location' => 'Praha', 'type' => 'outdoor', 'pricing' => ['price_czk' => 0, 'price_eur' => 0.0, 'is_free' => true]],
                ['id' => 'ATTR-PRG-02', 'name' => 'Národní muzeum', 'location' => 'Praha', 'type' => 'indoor', 'pricing' => ['price_czk' => 350, 'price_eur' => 14.0, 'is_free' => false]],
            ],
        ];
    }

    protected static function cityNotFound(): array
    {
        return ['error' => ['code' => 'CITY_NOT_FOUND', 'message' => 'Lokalita není v nabídce.']];
    }

    protected function pragueGeocoding(): void
    {
        $this->fake->json('GET', self::GEO, ['results' => [['name' => 'Praha', 'country' => 'Česko', 'latitude' => 50.088, 'longitude' => 14.4208]]]);
    }
}
