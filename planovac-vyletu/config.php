<?php

declare(strict_types=1);

$providers = [
    ['id' => 'ubytovani-a', 'type' => 'accommodation', 'name' => 'Ubytování A (Praha, Brno, Český Krumlov)', 'base_url' => 'http://localhost:8001', 'timeout' => 3.0],
    ['id' => 'ubytovani-b', 'type' => 'accommodation', 'name' => 'Ubytování B (Olomouc, Plzeň, Praha)',      'base_url' => 'http://localhost:8002', 'timeout' => 3.0],
    ['id' => 'atrakce-a',   'type' => 'attractions',   'name' => 'Lokální atrakce A',                         'base_url' => 'http://localhost:8101', 'timeout' => 2.0],
    ['id' => 'atrakce-b',   'type' => 'attractions',   'name' => 'Lokální atrakce B',                         'base_url' => 'http://localhost:8102', 'timeout' => 2.0],
];

if (($json = getenv('PROVIDERS_JSON')) !== false && $json !== '') {
    $providers = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

return [
    'providers' => $providers,
    'status_timeout' => 1.5,
    'weather' => [
        'geocoding_url' => 'https://geocoding-api.open-meteo.com/v1/search',
        'forecast_url' => 'https://api.open-meteo.com/v1/forecast',
        'timeout' => 4.0,
        'cache_ttl' => 1800,
        'forecast_days' => 16,
    ],
    'cache_dir' => getenv('CACHE_DIR') ?: __DIR__ . '/var/cache',
    'data_file' => getenv('DATA_FILE') ?: __DIR__ . '/data/orders.json',
    'timezone' => 'Europe/Prague',
];
