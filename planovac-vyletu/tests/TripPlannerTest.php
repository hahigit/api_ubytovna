<?php

declare(strict_types=1);

namespace Planovac\Tests;

use Planovac\ApiException;
use Planovac\Tests\Support\FakeHttp;
use Planovac\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;

final class TripPlannerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function input(array $override = []): array
    {
        return $override + [
            'city' => 'Praha',
            'date_from' => '2026-11-05',
            'date_to' => '2026-11-08',
            'people' => 2,
            'customer' => ['name' => 'Jan Novák', 'email' => 'jan.novak@example.cz'],
            'attractions' => [['source' => 'atrakce-a', 'id' => 'ATTR-PRG-01'], ['source' => 'atrakce-a', 'id' => 'ATTR-PRG-02']],
            'accommodation' => ['source' => 'ubytovani-b', 'hotel_id' => 'HOTEL-PRG-B1', 'room_id' => 'V3'],
        ];
    }

    private function reservationOk(): void
    {
        $this->fake->on('POST', self::ACC_B . '/make-reservation', fn (RequestInterface $r) => FakeHttp::response([
            'reservation_id' => 'RES-20261105-ABCD1234',
            'status' => 'confirmed',
            'hotel_id' => 'HOTEL-PRG-B1',
            'hotel_name' => 'Pension Vltava Residence',
            'room_id' => 'V3',
            'room_type' => 'Apartmá',
            'date_from' => '2026-11-05',
            'date_to' => '2026-11-08',
            'nights' => 3,
            'total_price_czk' => 9300,
            'total_price_eur' => 378.0,
        ], 201));
    }

    private function expectApiError(callable $fn, int $status, string $code): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            self::assertSame($status, $e->status, $e->getMessage());
            self::assertSame($code, $e->errorCode, $e->getMessage());
            return $e;
        }
        self::fail("Očekávána ApiException $status $code");
    }

    public function testConfirmedOrderReservesAtCorrectProviderAndComputesTotal(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $this->reservationOk();

        $order = $this->planner()->plan(self::input(), self::TODAY);

        self::assertSame('confirmed', $order['status']);
        self::assertMatchesRegularExpression('/^ORD-20261102-[0-9A-F]{8}$/', $order['order_id']);
        self::assertSame(['accommodation', 'attraction', 'attraction'], array_column($order['items'], 'type'));
        self::assertSame('RES-20261105-ABCD1234', $order['items'][0]['reservation_id']);
        // 9300 (ubytování) + 0 (Karlův most zdarma) + 2 × 350 (muzeum)
        self::assertSame(['czk' => 10000, 'eur' => 406.0], $order['total_price']);
        self::assertSame(0, $order['items'][1]['price_czk']);
        self::assertSame(700, $order['items'][2]['price_czk']);

        // Rezervace šla k vybranému poskytovateli se správnými údaji, k jinému ne.
        self::assertSame([], $this->fake->requestsTo(self::ACC_A));
        $sent = json_decode((string) $this->fake->requestsTo(self::ACC_B . '/make-reservation')[0]->getBody(), true);
        self::assertSame([
            'hotel_id' => 'HOTEL-PRG-B1', 'room_id' => 'V3', 'date_from' => '2026-11-05', 'date_to' => '2026-11-08',
            'people' => 2, 'guest_name' => 'Jan Novák', 'guest_email' => 'jan.novak@example.cz',
        ], $sent);

        self::assertSame($order, $this->orders()->find($order['order_id']));
    }

    public function testOrderWithAttractionsOnly(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $input = self::input();
        unset($input['accommodation']);

        $order = $this->planner()->plan($input, self::TODAY);

        self::assertSame('confirmed', $order['status']);
        self::assertSame(['czk' => 700, 'eur' => 28.0], $order['total_price']);
    }

    public function testFailedReservationStoresFailedOrderAndReturns409(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $this->fake->json('POST', self::ACC_B . '/make-reservation', ['error' => ['code' => 'ROOM_NOT_AVAILABLE', 'message' => 'Pokoj není volný.']], 409);

        $e = $this->expectApiError(fn () => $this->planner()->plan(self::input(), self::TODAY), 409, 'ACCOMMODATION_RESERVATION_FAILED');

        $order = $e->extra['order'];
        self::assertSame('failed', $order['status']);
        self::assertStringContainsString('Pokoj není volný.', $order['failure_reason']);
        self::assertSame('failed', $order['items'][0]['status']);
        self::assertSame(['cancelled', 'cancelled'], [$order['items'][1]['status'], $order['items'][2]['status']]);
        self::assertSame('failed', $this->orders()->find($order['order_id'])['status']);
        self::assertArrayHasKey('error', $e->toBody());
        self::assertArrayHasKey('order', $e->toBody());
    }

    public function testReservationTimeoutIs504(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $this->fake->timeout('POST', self::ACC_B . '/make-reservation');

        $e = $this->expectApiError(fn () => $this->planner()->plan(self::input(), self::TODAY), 504, 'ACCOMMODATION_RESERVATION_FAILED');
        self::assertStringContainsString('nelze ověřit', $e->extra['order']['failure_reason']);
    }

    public function testUnreachableAccommodationIs502(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $this->expectApiError(fn () => $this->planner()->plan(self::input(), self::TODAY), 502, 'ACCOMMODATION_RESERVATION_FAILED');
    }

    public function testUnknownSourceAndWrongTypeAreRejectedBeforeAnyCall(): void
    {
        $this->expectApiError(
            fn () => $this->planner()->plan(self::input(['accommodation' => ['source' => 'neexistuje', 'hotel_id' => 'H', 'room_id' => 'R']]), self::TODAY),
            400,
            'UNKNOWN_SOURCE',
        );
        $this->expectApiError(
            fn () => $this->planner()->plan(self::input(['accommodation' => ['source' => 'atrakce-a', 'hotel_id' => 'H', 'room_id' => 'R']]), self::TODAY),
            400,
            'SOURCE_TYPE_MISMATCH',
        );
        $this->expectApiError(
            fn () => $this->planner()->plan(self::input(['attractions' => [['source' => 'ubytovani-a', 'id' => 'X']]]), self::TODAY),
            400,
            'SOURCE_TYPE_MISMATCH',
        );
        self::assertSame([], $this->fake->requests);
    }

    public function testUnknownAttractionIs404AndNothingIsReserved(): void
    {
        $this->fake->json('GET', self::ATTR_A . '/attractions/date/', self::pragueAttractions());
        $this->reservationOk();

        $this->expectApiError(
            fn () => $this->planner()->plan(self::input(['attractions' => [['source' => 'atrakce-a', 'id' => 'ATTR-NEEXISTUJE']]]), self::TODAY),
            404,
            'ATTRACTION_NOT_FOUND',
        );
        self::assertSame([], $this->fake->requestsTo(self::ACC_B));
    }

    public function testAttractionProviderDownIs502(): void
    {
        $this->expectApiError(fn () => $this->planner()->plan(self::input(), self::TODAY), 502, 'UPSTREAM_ERROR');
        self::assertSame([], $this->fake->requestsTo(self::ACC_B), 'bez ověřených atrakcí se nic nerezervuje');
    }

    public function testValidationCollectsAllErrors(): void
    {
        $e = $this->expectApiError(fn () => $this->planner()->plan([
            'city' => '',
            'date_from' => '2026-11-10',
            'date_to' => '2026-11-10',
            'people' => 0,
            'customer' => ['name' => 'Jan', 'email' => 'spatny-email'],
            'attractions' => [['source' => 'atrakce-a']],
        ], self::TODAY), 400, 'VALIDATION_ERROR');

        $details = implode("\n", $e->details);
        self::assertStringContainsString("Chybí povinné pole 'city'", $details);
        self::assertStringContainsString('date_to', $details);
        self::assertStringContainsString("'people'", $details);
        self::assertStringContainsString('e-mailová adresa', $details);
        self::assertStringContainsString('attractions[0].id', $details);
    }

    public function testPastDateAndEmptyOrderAreRejected(): void
    {
        $e = $this->expectApiError(fn () => $this->planner()->plan(self::input([
            'date_from' => '2026-10-01', 'date_to' => '2026-10-03', 'attractions' => [],
        ]) , self::TODAY), 400, 'VALIDATION_ERROR');
        self::assertStringContainsString('minulosti', $e->getMessage());

        $input = self::input(['attractions' => []]);
        unset($input['accommodation']);
        $e = $this->expectApiError(fn () => $this->planner()->plan($input, self::TODAY), 400, 'VALIDATION_ERROR');
        self::assertStringContainsString('aspoň jednu atrakci', $e->getMessage());
    }

    public function testNonObjectBodyIsRejected(): void
    {
        $this->expectApiError(fn () => $this->planner()->plan([1, 2], self::TODAY), 400, 'VALIDATION_ERROR');
        $this->expectApiError(fn () => $this->planner()->plan('text', self::TODAY), 400, 'VALIDATION_ERROR');
    }
}
