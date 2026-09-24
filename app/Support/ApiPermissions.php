<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side mirror of the SPA's role / module access rules (canMod).
 */
class ApiPermissions
{
    /** @var list<string> */
    public const STAFF_ROLES = ['Admin', 'Manager', 'Accountant'];

    /**
     * Tables whose writes require a specific module. Tables not listed are writable by any staff role.
     *
     * @var array<string, list<string>>
     */
    private const TABLE_MODULES = [
        'employees' => ['hr'],
        'attendance' => ['hr'],
        'advance_requests' => ['hr'],
        'payroll_runs' => ['hr'],
        'payroll_slips' => ['hr'],
        'stewards' => ['steward'],
        'accounts_coa' => ['users'],
        'expense_entries' => ['users'],
        'journal_vouchers' => ['users'],
        'journal_entries' => ['users'],
        'system_settings' => ['settings'],
        'halls' => ['settings'],
        'function_types' => ['settings'],
        'meal_types' => ['settings'],
        'companies' => ['settings'],
    ];

    /**
     * Load the live user row behind a session so role / module changes and deletions apply immediately.
     *
     * @param  array{user_id: string, company_id: string, role: string, username: string}  $session
     * @return array{id: string, role: string, allowed_modules: ?list<string>}|null
     */
    public static function actor(array $session): ?array
    {
        $userId = trim($session['user_id'] ?? '');
        if ($userId === '' || ! Schema::hasTable('users')) {
            return null;
        }

        $query = DB::table('users')->where('id', $userId);
        if (($session['company_id'] ?? '') !== '' && Schema::hasColumn('users', 'company_id')) {
            $query->where('company_id', $session['company_id']);
        }
        $user = $query->first();
        if (! $user) {
            return null;
        }

        return [
            'id' => (string) $user->id,
            'role' => (string) ($user->role ?? ''),
            'allowed_modules' => self::decodeModules($user->allowed_modules ?? null),
        ];
    }

    /**
     * @param  array{id: string, role: string, allowed_modules: ?list<string>}  $actor
     */
    public static function isStaff(array $actor): bool
    {
        return in_array($actor['role'], self::STAFF_ROLES, true);
    }

    /**
     * @param  array{id: string, role: string, allowed_modules: ?list<string>}  $actor
     */
    public static function canUseModule(array $actor, string $module): bool
    {
        if (! self::isStaff($actor)) {
            return false;
        }
        if ($actor['role'] === 'Admin' || $module === 'dashboard') {
            return true;
        }

        $modules = $actor['allowed_modules'];

        return $modules === null || $modules === [] || in_array($module, $modules, true);
    }

    /**
     * @param  array{id: string, role: string, allowed_modules: ?list<string>}  $actor
     */
    public static function canWriteTable(array $actor, string $table): bool
    {
        if (! self::isStaff($actor)) {
            return false;
        }

        $modules = self::TABLE_MODULES[$table] ?? null;
        if ($modules === null) {
            return true;
        }

        foreach ($modules as $module) {
            if (self::canUseModule($actor, $module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ?list<string>
     */
    private static function decodeModules(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_map('strval', $value));
    }
}
