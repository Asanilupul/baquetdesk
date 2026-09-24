<?php

namespace App\Support;

/**
 * Must stay in sync with MENU_CAT_ORDER / catRank in resources/js/banquetdesk.app.js.
 */
class MenuCategoryOrder
{
    public const ORDER = [
        'WELCOME DRINK', 'SOUP', 'BREAD', 'BUTTER', 'APPETIZERS', 'SALADS', 'SAUCE DRESSINGS',
        'MAIN DISH', 'MEAT', 'SEAFOOD', 'VEGETABLES', 'CONDIMENTS', 'DESSERTS', 'LIVE ACTION',
    ];

    public static function normalize(?string $name): string
    {
        $name = strtoupper(trim((string) $name));
        $name = (string) preg_replace('/[_\/]+/', ' ', $name);

        return (string) preg_replace('/\s+/', ' ', $name);
    }

    public static function rank(?string $name): int
    {
        $normalized = self::normalize($name);
        if ($normalized === '') {
            return 9999;
        }

        $exact = array_search($normalized, self::ORDER, true);
        if ($exact !== false) {
            return $exact;
        }

        $stem = self::stem($normalized);
        foreach (self::ORDER as $index => $orderName) {
            if ($stem === self::stem($orderName)
                || str_starts_with($normalized, $orderName)
                || str_starts_with($orderName, $normalized)
                || str_contains($normalized, $orderName)
                || str_contains($orderName, $normalized)) {
                return $index;
            }
        }

        return 1000 + ord($normalized[0]);
    }

    /**
     * @template T of array{name: string}
     *
     * @param  list<T>  $categories
     * @return list<T>
     */
    public static function sort(array $categories): array
    {
        usort($categories, function (array $a, array $b): int {
            $byRank = self::rank($a['name']) <=> self::rank($b['name']);

            return $byRank !== 0 ? $byRank : strcmp(self::normalize($a['name']), self::normalize($b['name']));
        });

        return $categories;
    }

    private static function stem(string $name): string
    {
        return (string) preg_replace('/S\b/', '', $name);
    }
}
