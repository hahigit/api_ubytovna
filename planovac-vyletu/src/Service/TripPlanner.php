<?php

declare(strict_types=1);

namespace Planovac\Service;

use GuzzleHttp\Promise\Utils;
use Planovac\ApiException;
use Planovac\Dates;
use Planovac\PriceCalculator;
use Planovac\Provider;
use Planovac\ProviderRegistry;
use Planovac\Validator;

final class TripPlanner
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderGateway $gateway,
        private readonly OrderRepository $orders,
    ) {
    }

    public function plan(mixed $input, string $today): array
    {
        [$stay, $customer, $attractionRefs, $accommodationRef] = $this->validate($input, $today);

        $accommodationProvider = $accommodationRef !== null
            ? $this->registry->require($accommodationRef['source'], Provider::TYPE_ACCOMMODATION)
            : null;
        $attractionProviders = [];
        foreach ($attractionRefs as $ref) {
            $attractionProviders[$ref['source']] = $this->registry->require($ref['source'], Provider::TYPE_ATTRACTIONS);
        }

        $items = $this->attractionItems($attractionRefs, $attractionProviders, $stay);

        $failure = null;
        if ($accommodationProvider !== null) {
            try {
                $reservation = $this->gateway->makeReservation($accommodationProvider, [
                    'hotel_id' => $accommodationRef['hotel_id'],
                    'room_id' => $accommodationRef['room_id'],
                    'date_from' => $stay['date_from'],
                    'date_to' => $stay['date_to'],
                    'people' => $stay['people'],
                    'guest_name' => $customer['name'],
                    'guest_email' => $customer['email'],
                ]);
                array_unshift($items, self::accommodationItem($accommodationProvider, $reservation));
            } catch (UpstreamException $e) {
                $failure = $e;
                array_unshift($items, [
                    'type' => 'accommodation',
                    'source' => $accommodationProvider->id,
                    'provider_name' => $accommodationProvider->name,
                    'hotel_id' => $accommodationRef['hotel_id'],
                    'room_id' => $accommodationRef['room_id'],
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                    'price_czk' => 0,
                    'price_eur' => 0.0,
                ]);
            }
        }

        if ($failure !== null) {
            $items = array_map(fn ($i) => $i['type'] === 'attraction' ? [...$i, 'status' => 'cancelled'] : $i, $items);
        }

        $order = [
            'order_id' => 'ORD-' . str_replace('-', '', $today) . '-' . strtoupper(bin2hex(random_bytes(4))),
            'status' => $failure === null ? 'confirmed' : 'failed',
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'city' => $stay['city'],
            'date_from' => $stay['date_from'],
            'date_to' => $stay['date_to'],
            'nights' => Dates::nights($stay['date_from'], $stay['date_to']),
            'people' => $stay['people'],
            'customer' => $customer,
            'items' => $items,
            'total_price' => PriceCalculator::total($items),
        ];
        if ($failure !== null) {
            $order['failure_reason'] = 'Rezervace ubytování se nezdařila: ' . $failure->getMessage();
            if ($failure->isTimeout()) {
                $order['failure_reason'] .= ' Stav rezervace u poskytovatele nelze ověřit.';
            }
        }
        $this->orders->save($order);

        if ($failure !== null) {
            $status = in_array($failure->httpStatus, [400, 404, 409], true) ? 409 : $failure->gatewayStatus();
            throw new ApiException($status, 'ACCOMMODATION_RESERVATION_FAILED', $order['failure_reason'], extra: ['order' => $order]);
        }
        return $order;
    }

    private function validate(mixed $input, string $today): array
    {
        if (!is_array($input) || array_is_list($input) && $input !== []) {
            throw new ApiException(400, 'VALIDATION_ERROR', 'Tělo požadavku musí být JSON objekt.');
        }
        $v = new Validator($today);
        $stay = $v->stay($input['city'] ?? null, $input['date_from'] ?? null, $input['date_to'] ?? null, $input['people'] ?? null);

        $customer = ['name' => null, 'email' => null];
        if (!is_array($input['customer'] ?? null)) {
            $v->addError("Chybí povinné pole 'customer' (objekt s 'name' a 'email').");
        } else {
            $customer['name'] = $v->requireString($input['customer']['name'] ?? null, 'customer.name');
            $customer['email'] = $v->email($input['customer']['email'] ?? null, 'customer.email');
        }

        $attractions = [];
        $raw = $input['attractions'] ?? [];
        if (!is_array($raw) || !array_is_list($raw)) {
            $v->addError("Pole 'attractions' musí být seznam objektů {source, id}.");
        } else {
            $seen = [];
            foreach ($raw as $i => $ref) {
                $source = $v->requireString(is_array($ref) ? ($ref['source'] ?? null) : null, "attractions[$i].source");
                $id = $v->requireString(is_array($ref) ? ($ref['id'] ?? null) : null, "attractions[$i].id");
                if ($source === null || $id === null) {
                    continue;
                }
                if (isset($seen["$source|$id"])) {
                    $v->addError("Atrakce '$id' od '$source' je v objednávce vícekrát.");
                    continue;
                }
                $seen["$source|$id"] = true;
                $attractions[] = ['source' => $source, 'id' => $id];
            }
        }

        $accommodation = null;
        if (isset($input['accommodation'])) {
            $acc = $input['accommodation'];
            if (!is_array($acc)) {
                $v->addError("Pole 'accommodation' musí být objekt {source, hotel_id, room_id}.");
            } else {
                $source = $v->requireString($acc['source'] ?? null, 'accommodation.source');
                $hotelId = $v->requireString($acc['hotel_id'] ?? null, 'accommodation.hotel_id');
                $roomId = $v->requireString($acc['room_id'] ?? null, 'accommodation.room_id');
                if ($source !== null && $hotelId !== null && $roomId !== null) {
                    $accommodation = ['source' => $source, 'hotel_id' => $hotelId, 'room_id' => $roomId];
                }
            }
        }
        if (!isset($input['accommodation']) && is_array($raw) && $raw === []) {
            $v->addError('Objednávka musí obsahovat ubytování nebo aspoň jednu atrakci.');
        }

        $v->throwIfInvalid();
        return [$stay, $customer, $attractions, $accommodation];
    }

    private function attractionItems(array $refs, array $providers, array $stay): array
    {
        if ($refs === []) {
            return [];
        }
        $promises = array_map(fn (Provider $p) => $this->gateway->attractionsAsync($p, $stay['city']), $providers);
        $results = Utils::settle($promises)->wait();

        $catalog = [];
        foreach ($results as $source => $result) {
            if ($result['state'] !== 'fulfilled') {
                $e = $result['reason'];
                $status = $e instanceof UpstreamException ? $e->gatewayStatus() : 502;
                throw new ApiException($status, 'UPSTREAM_ERROR', "Nelze ověřit atrakce u poskytovatele '$source': " . OfferAggregator::reason($e));
            }
            foreach ($result['value']['attractions'] as $a) {
                $catalog[$source][$a['id']] = $a;
            }
        }

        $items = [];
        foreach ($refs as $ref) {
            $a = $catalog[$ref['source']][$ref['id']] ?? null;
            if ($a === null) {
                throw new ApiException(404, 'ATTRACTION_NOT_FOUND', "Atrakce '{$ref['id']}' u poskytovatele '{$ref['source']}' pro lokalitu '{$stay['city']}' neexistuje.");
            }
            $items[] = [
                'type' => 'attraction',
                'source' => $ref['source'],
                'provider_name' => $providers[$ref['source']]->name,
                'attraction_id' => $a['id'],
                'name' => $a['name'],
                'location' => $a['location'],
                'attraction_type' => $a['type'],
                'is_free' => $a['pricing']['is_free'],
                'status' => 'registered',
            ] + PriceCalculator::attraction($a['pricing'], $stay['people']);
        }
        return $items;
    }

    private static function accommodationItem(Provider $p, array $r): array
    {
        return [
            'type' => 'accommodation',
            'source' => $p->id,
            'provider_name' => $p->name,
            'reservation_id' => $r['reservation_id'],
            'hotel_id' => $r['hotel_id'] ?? null,
            'hotel_name' => $r['hotel_name'] ?? null,
            'room_id' => $r['room_id'] ?? null,
            'room_type' => $r['room_type'] ?? null,
            'date_from' => $r['date_from'] ?? null,
            'date_to' => $r['date_to'] ?? null,
            'nights' => $r['nights'] ?? null,
            'status' => 'confirmed',
            'price_czk' => $r['total_price_czk'],
            'price_eur' => (float) $r['total_price_eur'],
        ];
    }
}
