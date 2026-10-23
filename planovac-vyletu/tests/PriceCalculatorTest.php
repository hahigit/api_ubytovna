<?php

declare(strict_types=1);

namespace Planovac\Tests;

use PHPUnit\Framework\TestCase;
use Planovac\PriceCalculator;

final class PriceCalculatorTest extends TestCase
{
    public function testPaidAttractionIsMultipliedByPeople(): void
    {
        $line = PriceCalculator::attraction(['price_czk' => 350, 'price_eur' => 14.0, 'is_free' => false], 3);
        self::assertSame(1050, $line['price_czk']);
        self::assertSame(42.0, $line['price_eur']);
        self::assertSame(3, $line['quantity']);
    }

    public function testFreeAttractionCostsNothingEvenWithPrice(): void
    {
        $line = PriceCalculator::attraction(['price_czk' => 100, 'price_eur' => 4.0, 'is_free' => true], 4);
        self::assertSame(0, $line['price_czk']);
        self::assertSame(0.0, $line['price_eur']);
    }

    public function testEuroPricesAreRoundedToCents(): void
    {
        $line = PriceCalculator::attraction(['price_czk' => 280, 'price_eur' => 11.55, 'is_free' => false], 3);
        self::assertSame(34.65, $line['price_eur']);
    }

    public function testTotalSumsAllItems(): void
    {
        $total = PriceCalculator::total([
            ['price_czk' => 6800, 'price_eur' => 275.0],
            ['price_czk' => 700, 'price_eur' => 28.0],
            ['price_czk' => 0, 'price_eur' => 0.0],
            ['price_czk' => 900, 'price_eur' => 36.1],
        ]);
        self::assertSame(['czk' => 8400, 'eur' => 339.1], $total);
    }

    public function testEmptyOrder(): void
    {
        self::assertSame(['czk' => 0, 'eur' => 0.0], PriceCalculator::total([]));
    }
}
