<?php

namespace App\Support;

/**
 * Server-side mirror of public/combo-pricing.js for API/validation use.
 */
class ComboPricing
{
    public const TIERS = [50, 100, 150, 200, 250, 300];

    /**
     * @param  array<string, mixed>  $option
     * @return array{ok: bool, error?: string, pax: int, anchor: ?int, tier_price: float, extra_plate_qty: int, extra_plate_rate: float, extra_plate_total: float, base_total: float}
     */
    public static function calculate(array $option, int $pax): array
    {
        $rate = (float) ($option['extra_plate_price'] ?? 0);
        $tiers = $option['tiers'] ?? [];

        if ($pax < 50) {
            return [
                'ok' => false,
                'error' => 'Minimum 50 pax required for combo package pricing.',
                'pax' => $pax,
                'anchor' => null,
                'tier_price' => 0.0,
                'extra_plate_qty' => 0,
                'extra_plate_rate' => $rate,
                'extra_plate_total' => 0.0,
                'base_total' => 0.0,
            ];
        }

        $tierPrice = static function (int $anchor) use ($tiers): float {
            $key = (string) $anchor;

            return (float) ($tiers[$key] ?? $tiers[$anchor] ?? 0);
        };

        if (in_array($pax, self::TIERS, true)) {
            $price = $tierPrice($pax);

            return [
                'ok' => true,
                'pax' => $pax,
                'anchor' => $pax,
                'tier_price' => $price,
                'extra_plate_qty' => 0,
                'extra_plate_rate' => $rate,
                'extra_plate_total' => 0.0,
                'base_total' => $price,
            ];
        }

        if ($pax > 300) {
            $price = $tierPrice(300);
            $qty = $pax - 300;
            $extra = $qty * $rate;

            return [
                'ok' => true,
                'pax' => $pax,
                'anchor' => 300,
                'tier_price' => $price,
                'extra_plate_qty' => $qty,
                'extra_plate_rate' => $rate,
                'extra_plate_total' => $extra,
                'base_total' => $price + $extra,
            ];
        }

        $anchor = null;
        foreach (self::TIERS as $t) {
            if ($t <= $pax) {
                $anchor = $t;
            }
        }
        $price = $tierPrice((int) $anchor);
        $qty = $pax - (int) $anchor;
        $extra = $qty * $rate;

        return [
            'ok' => true,
            'pax' => $pax,
            'anchor' => $anchor,
            'tier_price' => $price,
            'extra_plate_qty' => $qty,
            'extra_plate_rate' => $rate,
            'extra_plate_total' => $extra,
            'base_total' => $price + $extra,
        ];
    }
}
