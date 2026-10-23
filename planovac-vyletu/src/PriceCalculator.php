<?php

declare(strict_types=1);

namespace Planovac;

final class PriceCalculator
{
    /**
     * Cena atrakce pro skupinu: jednotková cena × počet lidí, pokud není zdarma.
     * @param array{price_czk?: mixed, price_eur?: mixed, is_free?: mixed} $pricing
     * @return array{unit_price_czk: float|int, unit_price_eur: float, quantity: int, price_czk: float|int, price_eur: float}
     */
    public static function attraction(array $pricing, int $people): array
    {
        $free = ($pricing['is_free'] ?? false) === true;
        $czk = $free ? 0 : self::number($pricing['price_czk'] ?? 0);
        $eur = $free ? 0.0 : (float) self::number($pricing['price_eur'] ?? 0);
        return [
            'unit_price_czk' => $czk,
            'unit_price_eur' => round($eur, 2),
            'quantity' => $people,
            'price_czk' => self::roundCzk($czk * $people),
            'price_eur' => round($eur * $people, 2),
        ];
    }

    /**
     * Součet položek objednávky (každá položka má price_czk a price_eur).
     * @param list<array{price_czk: int|float, price_eur: int|float}> $items
     * @return array{czk: int|float, eur: float}
     */
    public static function total(array $items): array
    {
        $czk = 0;
        $eur = 0.0;
        foreach ($items as $item) {
            $czk += $item['price_czk'];
            $eur += $item['price_eur'];
        }
        return ['czk' => self::roundCzk($czk), 'eur' => round($eur, 2)];
    }

    private static function number(mixed $v): int|float
    {
        return is_int($v) || is_float($v) ? max(0, $v) : 0;
    }

    private static function roundCzk(int|float $v): int|float
    {
        $r = round($v, 2);
        return floor($r) == $r ? (int) $r : $r;
    }
}
