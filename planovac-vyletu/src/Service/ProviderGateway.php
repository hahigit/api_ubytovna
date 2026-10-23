<?php

declare(strict_types=1);

namespace Planovac\Service;

use GuzzleHttp\Promise\PromiseInterface;
use Planovac\Provider;

final class ProviderGateway
{
    public function __construct(private readonly HttpJson $http)
    {
    }

    public function describeAsync(Provider $provider, float $timeout): PromiseInterface
    {
        return $this->http->requestAsync('GET', $provider->url('/'), $timeout)->then(function (array $data) {
            $localities = $data['localities'] ?? null;
            if (!is_array($localities)) {
                throw new UpstreamException(UpstreamException::INVALID_RESPONSE, "Odpověď neobsahuje pole 'localities'.");
            }
            return [
                'service' => is_string($data['service'] ?? null) ? $data['service'] : null,
                'version' => is_string($data['version'] ?? null) ? $data['version'] : null,
                'localities' => array_values(array_filter($localities, 'is_string')),
            ];
        });
    }

    public function searchHotelsAsync(Provider $provider, array $query): PromiseInterface
    {
        return $this->http
            ->requestAsync('POST', $provider->url('/search-hotels'), $provider->timeout, ['json' => $query])
            ->then(
                function (array $data) {
                    if (!is_array($data['hotels'] ?? null)) {
                        throw new UpstreamException(UpstreamException::INVALID_RESPONSE, "Odpověď neobsahuje pole 'hotels'.");
                    }
                    return self::validateHotels($data['hotels']) + ['not_offered' => false];
                },
                function (UpstreamException $e) {
                    if ($e->httpStatus === 404 && $e->upstreamCode === 'CITY_NOT_FOUND') {
                        return ['hotels' => [], 'invalid' => 0, 'not_offered' => true];
                    }
                    throw $e;
                },
            );
    }

    public function attractionsAsync(Provider $provider, string $city): PromiseInterface
    {
        $url = $provider->url('/attractions/date/?' . http_build_query(['city' => $city]));
        return $this->http->requestAsync('GET', $url, $provider->timeout)->then(function (array $data) {
            if (!is_array($data['attractions'] ?? null)) {
                throw new UpstreamException(UpstreamException::INVALID_RESPONSE, "Odpověď neobsahuje pole 'attractions'.");
            }
            return self::validateAttractions($data['attractions']);
        });
    }

    public function makeReservation(Provider $provider, array $body): array
    {
        try {
            $data = $this->http->requestAsync('POST', $provider->url('/make-reservation'), $provider->timeout, ['json' => $body])->wait();
        } catch (\Throwable $e) {
            throw HttpJson::normalize($e, $provider->timeout);
        }
        if (!is_string($data['reservation_id'] ?? null) || ($data['status'] ?? null) !== 'confirmed'
            || !self::isNumber($data['total_price_czk'] ?? null) || !self::isNumber($data['total_price_eur'] ?? null)) {
            throw new UpstreamException(UpstreamException::INVALID_RESPONSE, 'Poskytovatel vrátil neúplné potvrzení rezervace.');
        }
        return $data;
    }

    public static function validateHotels(array $hotels): array
    {
        $valid = [];
        $invalid = 0;
        foreach ($hotels as $hotel) {
            if (!is_array($hotel) || !self::areStrings($hotel, ['hotel_id', 'name', 'location']) || !is_array($hotel['available_rooms'] ?? null)) {
                $invalid++;
                continue;
            }
            $rooms = [];
            foreach ($hotel['available_rooms'] as $room) {
                if (!is_array($room) || !self::areStrings($room, ['room_id', 'room_type']) || !is_int($room['capacity_persons'] ?? null)
                    || !self::isNumber($room['total_price_czk'] ?? null) || !self::isNumber($room['total_price_eur'] ?? null)) {
                    $invalid++;
                    continue;
                }
                $rooms[] = [
                    'room_id' => $room['room_id'],
                    'room_type' => $room['room_type'],
                    'capacity_persons' => $room['capacity_persons'],
                    'nights' => $room['nights'] ?? (is_array($room['availability'] ?? null) ? count($room['availability']) : null),
                    'total_price_czk' => $room['total_price_czk'],
                    'total_price_eur' => (float) $room['total_price_eur'],
                    'availability' => is_array($room['availability'] ?? null) ? array_map(self::normalizeNight(...), $room['availability']) : [],
                ];
            }
            if ($rooms !== []) {
                $valid[] = ['hotel_id' => $hotel['hotel_id'], 'name' => $hotel['name'], 'location' => $hotel['location'], 'available_rooms' => $rooms];
            }
        }
        return ['hotels' => $valid, 'invalid' => $invalid];
    }

    public static function validateAttractions(array $attractions): array
    {
        $valid = [];
        $invalid = 0;
        foreach ($attractions as $a) {
            $pricing = is_array($a) ? ($a['pricing'] ?? null) : null;
            if (!is_array($a) || !self::areStrings($a, ['id', 'name', 'location']) || !is_array($pricing)
                || (isset($pricing['is_free']) && !is_bool($pricing['is_free']))
                || (($pricing['is_free'] ?? false) !== true && (!self::isNumber($pricing['price_czk'] ?? null) || !self::isNumber($pricing['price_eur'] ?? null)))) {
                $invalid++;
                continue;
            }
            $valid[] = [
                'id' => $a['id'],
                'name' => $a['name'],
                'location' => $a['location'],
                'type' => is_string($a['type'] ?? null) ? $a['type'] : null,
                'pricing' => [
                    'price_czk' => $pricing['price_czk'] ?? 0,
                    'price_eur' => (float) ($pricing['price_eur'] ?? 0.0),
                    'is_free' => ($pricing['is_free'] ?? false) === true,
                ],
            ];
        }
        return ['attractions' => $valid, 'invalid' => $invalid];
    }

    private static function normalizeNight(mixed $night): mixed
    {
        if (is_array($night) && (is_int($night['price_eur'] ?? null))) {
            $night['price_eur'] = (float) $night['price_eur'];
        }
        return $night;
    }

    private static function areStrings(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!is_string($row[$key] ?? null) || $row[$key] === '') {
                return false;
            }
        }
        return true;
    }

    private static function isNumber(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && $v >= 0;
    }
}
