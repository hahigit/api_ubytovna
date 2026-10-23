<?php

declare(strict_types=1);

namespace Planovac;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Planovac\Http\AppFactory;
use Planovac\Service\FileCache;
use Planovac\Service\HttpJson;
use Planovac\Service\OfferAggregator;
use Planovac\Service\OrderRepository;
use Planovac\Service\ProviderGateway;
use Planovac\Service\TripPlanner;
use Planovac\Service\WeatherService;
use Slim\App;

/** Sestavení aplikace z konfigurace (sdílené pro public/index.php i testy). */
final class Bootstrap
{
    /**
     * @param array<string, mixed> $config obsah config.php
     * @param (\Closure(): string)|null $today
     */
    public static function app(array $config, ?ClientInterface $http = null, ?\Closure $today = null): App
    {
        date_default_timezone_set($config['timezone'] ?? 'Europe/Prague');
        $http ??= new Client(['headers' => ['User-Agent' => 'planovac-vyletu/1.0']]);
        $today ??= static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');

        $json = new HttpJson($http);
        $registry = new ProviderRegistry($config['providers']);
        $gateway = new ProviderGateway($json);
        $weather = new WeatherService($json, new FileCache($config['cache_dir'], (int) $config['weather']['cache_ttl']), $config['weather']);
        $orders = new OrderRepository($config['data_file']);

        return AppFactory::create(
            $registry,
            $gateway,
            new OfferAggregator($registry, $gateway, $weather),
            new TripPlanner($registry, $gateway, $orders),
            $orders,
            $today,
            (float) ($config['status_timeout'] ?? 1.5),
        );
    }
}
