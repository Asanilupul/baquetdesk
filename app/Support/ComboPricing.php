<?php

namespace App\Support;

/**
 * Server-side mirror of public/combo-pricing.js for API validation / lock.
 */
class ComboPricing
{
    public const TIERS = [50, 100, 150, 200, 250, 300];

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    public static function normalizeOption(array $option): array
    {
        $tiers = [];
        foreach (self::TIERS as $p) {
            $tiers[(string) $p] = 0.0;
        }
        $src = is_array($option['tiers'] ?? null) ? $option['tiers'] : [];
        foreach (self::TIERS as $p) {
            $key = (string) $p;
            $tiers[$key] = (float) ($src[$key] ?? $src[$p] ?? 0);
        }

        return [
            'id' => (string) ($option['id'] ?? ''),
            'menu_id' => (string) ($option['menu_id'] ?? ''),
            'menu_name' => (string) ($option['menu_name'] ?? ''),
            'extra_plate_price' => (float) ($option['extra_plate_price'] ?? 0),
            'tiers' => $tiers,
            'vendor_package_ids' => array_values(array_map('strval', is_array($option['vendor_package_ids'] ?? null) ? $option['vendor_package_ids'] : [])),
            'bite_lines' => is_array($option['bite_lines'] ?? null) ? $option['bite_lines'] : [],
            'softdrink_lines' => is_array($option['softdrink_lines'] ?? null) ? $option['softdrink_lines'] : [],
        ];
    }

    /**
     * Collapse legacy price / price_mode / single menu_id into menu_options[].
     *
     * @param  array<string, mixed>  $combo
     * @return array<string, mixed>
     */
    public static function normalizeCombo(array $combo): array
    {
        $options = [];
        if (is_array($combo['menu_options'] ?? null)) {
            foreach ($combo['menu_options'] as $opt) {
                if (is_array($opt)) {
                    $options[] = self::normalizeOption($opt);
                }
            }
        }

        if (! $options) {
            $price = (float) ($combo['price'] ?? 0);
            $mode = (string) ($combo['price_mode'] ?? 'total');
            $paxHint = (int) ($combo['pax_count'] ?? 100);
            if ($paxHint < 50) {
                $paxHint = 100;
            }
            $tierTotal = $price;
            if ($mode === 'per_person' && $price > 0) {
                $tierTotal = $price * $paxHint;
            }
            if ($price > 0 || ! empty($combo['menu_id'])) {
                $tiers = [];
                foreach (self::TIERS as $p) {
                    $tiers[(string) $p] = $tierTotal;
                }
                $options[] = self::normalizeOption([
                    'id' => 'legacy_'.($combo['id'] ?? 'x'),
                    'menu_id' => (string) ($combo['menu_id'] ?? ''),
                    'menu_name' => (string) ($combo['function_menu'] ?? $combo['name'] ?? ''),
                    'extra_plate_price' => (float) ($combo['menu_unit_price'] ?? 0),
                    'tiers' => $tiers,
                    'vendor_package_ids' => $combo['vendor_package_ids'] ?? [],
                    'bite_lines' => $combo['bite_lines'] ?? [],
                    'softdrink_lines' => $combo['softdrink_lines'] ?? [],
                ]);
            }
        }

        $combo['menu_options'] = $options;

        return $combo;
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array{ok: bool, error?: string, pax: int, anchor: ?int, tier_price: float, extra_plate_qty: int, extra_plate_rate: float, extra_plate_total: float, base_total: float}
     */
    public static function calculate(array $option, int $pax): array
    {
        $option = self::normalizeOption($option);
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

    /**
     * Recompute and lock pricing_snapshot base totals for a function row.
     *
     * @param  array<string, mixed>  $functionRow
     * @param  array<string, mixed>|null  $comboRow  decoded combo_packages row
     * @return array<string, mixed>
     */
    public static function lockFunctionSnapshot(array $functionRow, ?array $comboRow): array
    {
        if (! $comboRow) {
            return $functionRow;
        }

        $combo = self::normalizeCombo($comboRow);
        $options = $combo['menu_options'] ?? [];
        if (! $options) {
            return $functionRow;
        }

        $optId = (string) ($functionRow['combo_menu_option_id'] ?? '');
        $opt = $options[0];
        foreach ($options as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $optId) {
                $opt = $candidate;
                break;
            }
        }

        $pax = (int) ($functionRow['pax_count'] ?? 0);
        $calc = self::calculate($opt, $pax);
        if (! ($calc['ok'] ?? false)) {
            return $functionRow;
        }

        $snap = $functionRow['pricing_snapshot'] ?? null;
        if (is_string($snap)) {
            $decoded = json_decode($snap, true);
            $snap = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($snap)) {
            $snap = [];
        }

        $optionalTotal = (float) ($snap['optional_total'] ?? 0);
        if (isset($snap['optional_extras']) && is_array($snap['optional_extras'])) {
            $optionalTotal = 0.0;
            $includedBites = [];
            foreach ($opt['bite_lines'] ?? [] as $line) {
                $includedBites[(string) ($line['extra_id'] ?? '')] = true;
            }
            $includedDrinks = [];
            foreach ($opt['softdrink_lines'] ?? [] as $line) {
                $includedDrinks[(string) ($line['extra_id'] ?? '')] = true;
            }
            $includedVendors = [];
            foreach ($opt['vendor_package_ids'] ?? [] as $vid) {
                $includedVendors[(string) $vid] = true;
            }
            $clean = [];
            foreach ($snap['optional_extras'] as $ex) {
                if (! is_array($ex)) {
                    continue;
                }
                $kind = (string) ($ex['kind'] ?? 'bite');
                $id = (string) ($ex['id'] ?? '');
                if ($kind === 'bite' && isset($includedBites[$id])) {
                    continue;
                }
                if ($kind === 'softdrink' && isset($includedDrinks[$id])) {
                    continue;
                }
                if ($kind === 'vendor' && isset($includedVendors[$id])) {
                    continue;
                }
                $lineTotal = (float) ($ex['line_total'] ?? ((float) ($ex['quantity'] ?? 1) * (float) ($ex['unit_price'] ?? 0)));
                $optionalTotal += $lineTotal;
                $clean[] = $ex;
            }
            $snap['optional_extras'] = $clean;
        }

        $snap['version'] = (int) ($snap['version'] ?? 1);
        $snap['combo_menu_option_id'] = $opt['id'] ?? null;
        $snap['menu_id'] = $opt['menu_id'] ?? null;
        $snap['menu_name'] = $opt['menu_name'] ?? ($snap['menu_name'] ?? '');
        $snap['pax'] = $calc['pax'];
        $snap['anchor'] = $calc['anchor'];
        $snap['tier_price'] = $calc['tier_price'];
        $snap['extra_plate_qty'] = $calc['extra_plate_qty'];
        $snap['extra_plate_rate'] = $calc['extra_plate_rate'];
        $snap['extra_plate_total'] = $calc['extra_plate_total'];
        $snap['base_total'] = $calc['base_total'];
        $snap['optional_total'] = $optionalTotal;
        $snap['grand_total'] = $calc['base_total'] + $optionalTotal;
        $snap['server_locked_at'] = now()->toIso8601String();

        $functionRow['pricing_snapshot'] = $snap;
        if ($calc['pax'] > 0) {
            $functionRow['menu_price'] = round($calc['base_total'] / $calc['pax'], 2);
        }

        return $functionRow;
    }
}
