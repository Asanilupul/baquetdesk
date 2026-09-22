<?php

namespace App\Http\Controllers;

use App\Support\ComboPricing;
use App\Support\CompanyApiSession;
use App\Support\CompanySubscription;
use App\Support\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class RestQueryController extends Controller
{
    /** @var array<string, list<string>> */
    private static array $columnCache = [];

    /** @var array<string, array{column: string, ascending: bool}> */
    private array $bootstrapOrder = [
        'employees' => ['column' => 'name', 'ascending' => true],
        'functions' => ['column' => 'function_date', 'ascending' => false],
        'walking_inquiries' => ['column' => 'inquiry_date', 'ascending' => false],
        'quotations' => ['column' => 'created_at', 'ascending' => false],
        'suppliers' => ['column' => 'name', 'ascending' => true],
        'supplier_products' => ['column' => 'product_name', 'ascending' => true],
        'purchase_orders' => ['column' => 'created_at', 'ascending' => false],
        'supplier_payments' => ['column' => 'payment_date', 'ascending' => false],
        'menu_categories' => ['column' => 'name', 'ascending' => true],
        'menu_items' => ['column' => 'name', 'ascending' => true],
        'menus' => ['column' => 'name', 'ascending' => true],
        'menu_addons' => ['column' => 'name', 'ascending' => true],
        'kitchen_sheets' => ['column' => 'created_at', 'ascending' => false],
        'store_items' => ['column' => 'name', 'ascending' => true],
        'store_transactions' => ['column' => 'created_at', 'ascending' => false],
        'vendors' => ['column' => 'vendor_name', 'ascending' => true],
        'vendor_categories' => ['column' => 'name', 'ascending' => true],
        'vendor_packages' => ['column' => 'name', 'ascending' => true],
        'accounts_coa' => ['column' => 'account_code', 'ascending' => true],
        'journal_entries' => ['column' => 'entry_date', 'ascending' => false],
        'expense_entries' => ['column' => 'expense_date', 'ascending' => false],
        'invoices' => ['column' => 'created_at', 'ascending' => false],
        'halls' => ['column' => 'name', 'ascending' => true],
        'menu_extras' => ['column' => 'name', 'ascending' => true],
        'combo_packages' => ['column' => 'name', 'ascending' => true],
    ];

    /** @var list<string> */
    private array $allowedTables = [
        'users', 'employees', 'stewards', 'attendance', 'advance_requests', 'payments',
        'functions', 'walking_inquiries', 'quotations', 'invoices', 'suppliers',
        'supplier_products', 'purchase_orders', 'supplier_payments', 'menu_categories',
        'menu_items', 'menus', 'menu_hall_prices', 'menu_category_configs', 'menu_selections',
        'function_menu_selections', 'menu_addons', 'function_menu_addons', 'function_menu_extras', 'kitchen_sheets',
        'store_items', 'item_recipes', 'store_transactions', 'vendors', 'vendor_categories',
        'vendor_packages', 'accounts_coa', 'journal_entries', 'expense_entries', 'function_sheets',
        'system_settings', 'production_balancing', 'halls', 'menu_extras', 'combo_packages', 'companies',
    ];

    /** @var array<string, list<string>> */
    private array $jsonColumns = [
        'quotations' => ['items'],
        'invoices' => ['items', 'client_reference'],
        'purchase_orders' => ['items'],
        'kitchen_sheets' => ['selected_items', 'bites', 'soft_drinks'],
        'vendors' => ['pictures', 'packages'],
        'vendor_packages' => ['categories'],
        'function_sheets' => ['sheet_data'],
        'production_balancing' => ['in_store_items', 'processing_items', 'daily_sales'],
        'combo_packages' => ['vendor_package_ids', 'bite_lines', 'softdrink_lines', 'menu_options'],
        'functions' => ['pricing_snapshot', 'optional_vendor_extras'],
    ];

    /** @var array<string, list<string>> */
    private array $boolColumns = [
        'stewards' => ['isPaid'],
        'advance_requests' => ['is_paid', 'is_reconciled'],
        'payments' => ['is_reconciled', 'is_refunded'],
        'accounts_coa' => ['is_active'],
    ];

    public function handle(Request $request): JsonResponse
    {
        try {
            $table = (string) $request->input('table', '');
            if (! in_array($table, $this->allowedTables, true)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => "Unknown or disallowed table: {$table}"],
                ], 400);
            }

            $companyId = $this->resolveCompanyId($request);
            $action = (string) $request->input('action', 'select');
            $filters = $request->input('filters', []);
            $order = $request->input('order');
            $limit = $request->input('limit');
            $single = (bool) $request->input('single', false);
            $maybeSingle = (bool) $request->input('maybeSingle', false);
            $select = $request->input('select', '*');
            $payload = $request->input('payload');
            $onConflict = $request->input('onConflict');
            $returnRows = (bool) $request->input('returnRows', false);

            // Public vendor self-registration is the only unscoped write allowed
            $isPublicVendorRegister = $table === 'vendors' && $action === 'insert' && $companyId === '';
            $session = null;

            if (! $isPublicVendorRegister) {
                $session = CompanyApiSession::fromRequest($request);
                if ($session === null) {
                    return response()->json([
                        'data' => null,
                        'error' => ['message' => 'Authentication required. Please log in again.'],
                    ], 401);
                }
                // Token company is authoritative (prevents X-Company-Id spoofing)
                if ($session['company_id'] !== '') {
                    $companyId = $session['company_id'];
                }
            }

            if ($companyId === '' && ! $isPublicVendorRegister) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Company session required (X-Company-Id).'],
                ], 401);
            }

            if (in_array($table, ['companies'], true) && in_array($action, ['insert', 'delete', 'upsert'], true)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Company records cannot be created or deleted via this API'],
                ], 403);
            }

            if ($companyId !== '') {
                if ($deny = $this->denyIfCompanyUnusable($companyId)) {
                    return $deny;
                }
            }

            // Only Admins may create/change user roles
            if ($table === 'users' && in_array($action, ['insert', 'update', 'upsert'], true)) {
                $actorRole = $session['role'] ?? '';
                if ($actorRole !== 'Admin') {
                    $payloadRows = is_array($payload) ? (array_is_list($payload) ? $payload : [$payload]) : [];
                    foreach ($payloadRows as $row) {
                        if (is_array($row) && array_key_exists('role', $row)) {
                            return response()->json([
                                'data' => null,
                                'error' => ['message' => 'Only Admins can manage user roles'],
                            ], 403);
                        }
                    }
                }
            }

            // Never allow password filters (use /api/auth/login)
            if (is_array($filters)) {
                foreach ($filters as $filter) {
                    if (is_array($filter) && strtolower((string) ($filter['column'] ?? '')) === 'password') {
                        return response()->json([
                            'data' => null,
                            'error' => ['message' => 'Password filter is not allowed'],
                        ], 400);
                    }
                }
            }

            $result = match ($action) {
                'select' => $this->runSelect($table, $select, $filters, $order, $limit, $single, $maybeSingle, $companyId),
                'insert' => $this->runInsert($table, $payload, $returnRows || $single || $maybeSingle, $single, $maybeSingle, $companyId),
                'update' => $this->runUpdate($table, $payload, $filters, $returnRows || $single || $maybeSingle, $single, $maybeSingle, $companyId),
                'delete' => $this->runDelete($table, $filters, $companyId),
                'upsert' => $this->runUpsert($table, $payload, $onConflict, $returnRows || $single || $maybeSingle, $single, $maybeSingle, $companyId),
                default => throw new \InvalidArgumentException("Unsupported action: {$action}"),
            };

            if (is_array($result) && array_key_exists('__error', $result)) {
                return response()->json([
                    'data' => $result['data'] ?? null,
                    'error' => $result['__error'],
                ]);
            }

            return response()->json(['data' => $result, 'error' => null]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }

    public function bootstrap(Request $request): JsonResponse
    {
        try {
            $started = microtime(true);
            $attendanceStart = (string) $request->input('attendance_start', '');
            $attendanceEnd = (string) $request->input('attendance_end', '');
            $companyId = $this->resolveCompanyId($request);

            $session = CompanyApiSession::fromRequest($request);
            if ($session === null) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Authentication required. Please log in again.'],
                ], 401);
            }
            if ($session['company_id'] !== '') {
                $companyId = $session['company_id'];
            }

            if ($companyId === '') {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Company session required (X-Company-Id).'],
                ], 401);
            }

            if ($deny = $this->denyIfCompanyUnusable($companyId)) {
                return $deny;
            }

            $payload = [];
            $bootstrapErrors = [];
            foreach ($this->allowedTables as $table) {
                if ($table === 'attendance' || $table === 'system_settings') {
                    continue;
                }

                try {
                    $query = DB::table($table);
                    $this->applyCompanyScope($query, $table, $companyId);
                    $order = $this->bootstrapOrder[$table] ?? null;
                    if ($order) {
                        $query->orderBy($order['column'], $order['ascending'] ? 'asc' : 'desc');
                    }
                    $payload[$table] = $query->get()
                        ->map(fn ($row) => $this->decodeRow($table, (array) $row))
                        ->all();
                } catch (Throwable $e) {
                    $payload[$table] = [];
                    $bootstrapErrors[$table] = $e->getMessage();
                    Log::warning('Bootstrap table failed', [
                        'table' => $table,
                        'company_id' => $companyId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($bootstrapErrors !== []) {
                $payload['_bootstrap_errors'] = $bootstrapErrors;
            }

            // Branding: prefer companies table, fall back to system_settings
            $settings = [];
            if ($companyId !== '' && Schema::hasTable('companies')) {
                $company = DB::table('companies')->where('id', $companyId)->first();
                if ($company) {
                    $map = [
                        'company_name' => $company->name ?? '',
                        'company_subtitle' => $company->subtitle ?? '',
                        'company_phone' => $company->phone ?? '',
                        'company_email' => $company->email ?? '',
                        'company_address' => $company->address ?? '',
                        'company_logo' => $company->logo ?? '',
                    ];
                    foreach ($map as $key => $value) {
                        $settings[] = ['key' => $key, 'value' => (string) $value];
                    }
                }
            }
            if (! $settings) {
                $settings = DB::table('system_settings')->get()
                    ->map(function ($row) {
                        $arr = (array) $row;
                        if (($arr['key'] ?? '') === 'company_logo') {
                            $arr['value'] = '';
                            $arr['deferred'] = true;
                        }

                        return $this->decodeRow('system_settings', $arr);
                    })
                    ->all();
            }
            $payload['system_settings'] = $settings;
            $payload['companies'] = $companyId !== '' && Schema::hasTable('companies')
                ? DB::table('companies')->where('id', $companyId)->get()->map(fn ($r) => (array) $r)->all()
                : [];

            $attendanceQuery = DB::table('attendance');
            $this->applyCompanyScope($attendanceQuery, 'attendance', $companyId);
            if ($attendanceStart !== '' && $attendanceEnd !== '') {
                $attendanceQuery->where('date', '>=', $attendanceStart)->where('date', '<=', $attendanceEnd);
            }
            $payload['attendance'] = $attendanceQuery
                ->limit(10000)
                ->get()
                ->map(fn ($row) => $this->decodeRow('attendance', (array) $row))
                ->all();

            return response()->json([
                'data' => $payload,
                'error' => null,
                'meta' => [
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                    'tables' => count($payload),
                    'company_id' => $companyId,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }

    private function resolveCompanyId(Request $request): string
    {
        $id = trim((string) ($request->header('X-Company-Id') ?: $request->input('company_id', '')));

        return $id;
    }

    private function denyIfCompanyUnusable(string $companyId): ?JsonResponse
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'subscription_status')) {
            return null;
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        if (! $company) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Company not found'],
            ], 404);
        }

        if (CompanySubscription::isUsable($company)) {
            return null;
        }

        return response()->json([
            'data' => null,
            'error' => ['message' => CompanySubscription::denyMessage($company)],
        ], 403);
    }

    private function blockUnusableCompanyLogin(mixed $result, bool $single): ?JsonResponse
    {
        $user = null;
        if ($single && is_array($result)) {
            $user = $result;
        } elseif (is_array($result) && isset($result[0]) && is_array($result[0])) {
            $user = $result[0];
        }

        if (! is_array($user)) {
            return null;
        }

        // SuperAdmin may log in via users table without company scope
        if (($user['role'] ?? '') === 'SuperAdmin') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Use /su-admin for Super Admin login'],
            ], 403);
        }

        $companyId = trim((string) ($user['company_id'] ?? ''));
        if ($companyId === '' || ! Schema::hasTable('companies')) {
            return null;
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        if (CompanySubscription::isUsable($company)) {
            return null;
        }

        return response()->json([
            'data' => null,
            'error' => ['message' => CompanySubscription::denyMessage($company)],
        ], 403);
    }

    private function applyCompanyScope($query, string $table, string $companyId): void
    {
        if ($companyId === '') {
            return;
        }

        if ($table === 'companies') {
            $query->where('id', $companyId);

            return;
        }

        if ($table === 'system_settings') {
            return;
        }

        if (! Schema::hasColumn($table, 'company_id')) {
            return;
        }

        $query->where('company_id', $companyId);
    }

    /** @return list<string> */
    private function columnsFor(string $table): array
    {
        return self::$columnCache[$table] ??= Schema::getColumnListing($table);
    }

    private function runSelect(
        string $table,
        mixed $select,
        mixed $filters,
        mixed $order,
        mixed $limit,
        bool $single,
        bool $maybeSingle,
        string $companyId = ''
    ): mixed {
        $query = DB::table($table);
        $this->applyCompanyScope($query, $table, $companyId);
        $this->applyFilters($query, $filters, $table);

        if (is_string($select) && $select !== '*') {
            $allowed = $this->columnsFor($table);
            $cols = array_values(array_filter(
                array_map('trim', explode(',', $select)),
                fn ($col) => $col !== '' && in_array($col, $allowed, true)
            ));
            if ($cols === []) {
                throw new \InvalidArgumentException('No valid select columns');
            }
            $query->select($cols);
        }

        if (is_array($order) && ! empty($order['column'])) {
            $orderColumn = (string) $order['column'];
            if (! in_array($orderColumn, $this->columnsFor($table), true)) {
                throw new \InvalidArgumentException("Unknown order column: {$orderColumn}");
            }
            $query->orderBy($orderColumn, ($order['ascending'] ?? true) ? 'asc' : 'desc');
        }

        if ($limit !== null && $limit !== '') {
            $query->limit(min(10000, max(1, (int) $limit)));
        }

        $rows = $query->get()->map(fn ($row) => $this->decodeRow($table, (array) $row))->all();

        return $this->shapeResult($rows, $single, $maybeSingle);
    }

    private function runInsert(
        string $table,
        mixed $payload,
        bool $returnRows,
        bool $single,
        bool $maybeSingle,
        string $companyId = ''
    ): mixed {
        $rows = $this->normalizeRows($payload);
        $prepared = [];

        foreach ($rows as $row) {
            if ($companyId !== '' && Schema::hasColumn($table, 'company_id')) {
                $row['company_id'] = $companyId;
            }
            $row = $this->applyDomainWriteHooks($table, $row, $companyId, true);
            $prepared[] = $this->prepareWriteRow($table, $row, true);
        }

        DB::table($table)->insert($prepared);

        foreach ($prepared as $row) {
            $this->afterDomainWrite($table, $this->decodeRow($table, $row), $companyId, 'insert');
        }

        if (! $returnRows) {
            return null;
        }

        $decoded = array_map(fn ($row) => $this->decodeRow($table, $row), $prepared);

        return $this->shapeResult($decoded, $single, $maybeSingle);
    }

    private function runUpdate(
        string $table,
        mixed $payload,
        mixed $filters,
        bool $returnRows,
        bool $single,
        bool $maybeSingle,
        string $companyId = ''
    ): mixed {
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Update payload must be an object');
        }

        $query = DB::table($table);
        $this->applyCompanyScope($query, $table, $companyId);
        $this->applyFilters($query, $filters, $table);

        $payload = $this->applyDomainWriteHooks($table, is_array($payload) ? $payload : [], $companyId, false);
        $update = $this->prepareWriteRow($table, $payload, false);
        unset($update['id'], $update['key'], $update['company_id']);

        $update['updated_at'] = now();

        $query->update($update);

        if ($table === 'payments') {
            $fresh = DB::table($table);
            $this->applyCompanyScope($fresh, $table, $companyId);
            $this->applyFilters($fresh, $filters, $table);
            foreach ($fresh->get() as $row) {
                $this->afterDomainWrite($table, $this->decodeRow($table, (array) $row), $companyId, 'update');
            }
        }

        if (! $returnRows) {
            return null;
        }

        $rows = DB::table($table);
        $this->applyCompanyScope($rows, $table, $companyId);
        $this->applyFilters($rows, $filters, $table);
        $decoded = $rows->get()->map(fn ($row) => $this->decodeRow($table, (array) $row))->all();

        return $this->shapeResult($decoded, $single, $maybeSingle);
    }

    private function runDelete(string $table, mixed $filters, string $companyId = ''): mixed
    {
        $query = DB::table($table);
        $this->applyCompanyScope($query, $table, $companyId);
        $this->applyFilters($query, $filters, $table);

        $toDelete = [];
        if ($table === 'payments') {
            $toDelete = $query->get()->map(fn ($row) => $this->decodeRow($table, (array) $row))->all();
        }

        $query->delete();

        foreach ($toDelete as $row) {
            $this->afterDomainWrite($table, $row, $companyId, 'delete');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyDomainWriteHooks(string $table, array $row, string $companyId, bool $isInsert): array
    {
        if ($table === 'combo_packages') {
            $decoded = $row;
            if (isset($decoded['menu_options']) && is_string($decoded['menu_options'])) {
                $decoded['menu_options'] = json_decode($decoded['menu_options'], true) ?: [];
            }
            foreach (['vendor_package_ids', 'bite_lines', 'softdrink_lines'] as $jsonKey) {
                if (isset($decoded[$jsonKey]) && is_string($decoded[$jsonKey])) {
                    $decoded[$jsonKey] = json_decode($decoded[$jsonKey], true) ?: [];
                }
            }
            $normalized = ComboPricing::normalizeCombo($decoded);
            $row['menu_options'] = $normalized['menu_options'] ?? [];

            return $row;
        }

        if ($table === 'functions' && (! empty($row['combo_package_id']) || ($row['package_source'] ?? '') === 'combo')) {
            $comboId = (string) ($row['combo_package_id'] ?? '');
            $combo = null;
            if ($comboId !== '' && Schema::hasTable('combo_packages')) {
                $cq = DB::table('combo_packages')->where('id', $comboId);
                if ($companyId !== '' && Schema::hasColumn('combo_packages', 'company_id')) {
                    $cq->where('company_id', $companyId);
                }
                $found = $cq->first();
                if ($found) {
                    $combo = $this->decodeRow('combo_packages', (array) $found);
                }
            }
            if (isset($row['pricing_snapshot']) && is_string($row['pricing_snapshot'])) {
                $row['pricing_snapshot'] = json_decode($row['pricing_snapshot'], true) ?: [];
            }
            if (isset($row['optional_vendor_extras']) && is_string($row['optional_vendor_extras'])) {
                $row['optional_vendor_extras'] = json_decode($row['optional_vendor_extras'], true) ?: [];
            }

            return ComboPricing::lockFunctionSnapshot($row, $combo);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function afterDomainWrite(string $table, array $row, string $companyId, string $action): void
    {
        if ($table !== 'payments' || $companyId === '') {
            return;
        }

        try {
            if ($action === 'delete') {
                PaymentLedger::void($companyId, (string) ($row['id'] ?? ''));
            } else {
                PaymentLedger::sync($companyId, $row);
            }
        } catch (Throwable $e) {
            Log::warning('Payment ledger sync failed', [
                'payment_id' => $row['id'] ?? null,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function runUpsert(
        string $table,
        mixed $payload,
        mixed $onConflict,
        bool $returnRows,
        bool $single,
        bool $maybeSingle,
        string $companyId = ''
    ): mixed {
        $rows = $this->normalizeRows($payload);
        $conflictCols = array_values(array_filter(array_map('trim', explode(',', (string) ($onConflict ?: 'id')))));
        $saved = [];

        foreach ($rows as $row) {
            if ($companyId !== '' && Schema::hasColumn($table, 'company_id')) {
                $row['company_id'] = $companyId;
            }
            $row = $this->applyDomainWriteHooks($table, $row, $companyId, true);
            $prepared = $this->prepareWriteRow($table, $row, true);
            $match = [];

            foreach ($conflictCols as $col) {
                if (! array_key_exists($col, $prepared)) {
                    throw new \InvalidArgumentException("Upsert conflict column missing: {$col}");
                }
                $match[$col] = $prepared[$col];
            }

            $existing = DB::table($table);
            foreach ($match as $col => $val) {
                $existing->where($col, $val);
            }
            $found = $existing->first();

            if ($found) {
                $update = $this->prepareWriteRow($table, $row, false);
                if ($table === 'system_settings') {
                    unset($update['key'], $update['created_at']);
                } else {
                    unset($update['id'], $update['created_at']);
                }
                $update['updated_at'] = now();

                $q = DB::table($table);
                foreach ($match as $col => $val) {
                    $q->where($col, $val);
                }
                $q->update($update);

                $fresh = DB::table($table);
                foreach ($match as $col => $val) {
                    $fresh->where($col, $val);
                }
                $decoded = $this->decodeRow($table, (array) $fresh->first());
                $this->afterDomainWrite($table, $decoded, $companyId, 'update');
                $saved[] = $decoded;
            } else {
                DB::table($table)->insert($prepared);
                $decoded = $this->decodeRow($table, $prepared);
                $this->afterDomainWrite($table, $decoded, $companyId, 'insert');
                $saved[] = $decoded;
            }
        }

        if (! $returnRows) {
            return null;
        }

        return $this->shapeResult($saved, $single, $maybeSingle);
    }

    private function applyFilters($query, mixed $filters, ?string $table = null): void
    {
        if (! is_array($filters)) {
            return;
        }

        $allowedColumns = $table ? $this->columnsFor($table) : null;

        foreach ($filters as $filter) {
            if (! is_array($filter) || empty($filter['column'])) {
                continue;
            }

            $column = (string) $filter['column'];
            if ($allowedColumns !== null && ! in_array($column, $allowedColumns, true)) {
                throw new \InvalidArgumentException("Unknown filter column: {$column}");
            }

            $op = $filter['op'] ?? 'eq';
            $value = $filter['value'] ?? null;

            match ($op) {
                'eq' => $query->where($column, $value),
                'neq' => $query->where($column, '!=', $value),
                'gte' => $query->where($column, '>=', $value),
                'lte' => $query->where($column, '<=', $value),
                'gt' => $query->where($column, '>', $value),
                'lt' => $query->where($column, '<', $value),
                'ilike' => $query->where($column, 'like', str_replace('%', '', (string) $value) === (string) $value
                    ? '%'.$value.'%'
                    : str_replace(['*', '?'], ['%', '_'], (string) $value)),
                default => throw new \InvalidArgumentException("Unsupported filter op: {$op}"),
            };
        }
    }

    /** @return list<array<string, mixed>> */
    private function normalizeRows(mixed $payload): array
    {
        if ($payload === null) {
            throw new \InvalidArgumentException('Payload is required');
        }

        if (array_is_list($payload)) {
            return array_map(fn ($row) => (array) $row, $payload);
        }

        return [(array) $payload];
    }

    /** @param array<string, mixed> $row */
    private function prepareWriteRow(string $table, array $row, bool $isInsert): array
    {
        $columns = $this->columnsFor($table);
        $out = [];

        // Privilege / billing fields must not be client-writable via RestQuery
        $blocked = [];
        if ($table === 'companies') {
            $blocked = ['subscription_plan', 'subscription_expires_at', 'subscription_status', 'status'];
        }
        if ($table === 'users') {
            $blocked = [];
        }

        foreach ($row as $key => $value) {
            if (! in_array($key, $columns, true)) {
                continue;
            }
            if (in_array($key, $blocked, true)) {
                continue;
            }

            if (in_array($key, $this->jsonColumns[$table] ?? [], true)) {
                $out[$key] = is_string($value) ? $value : json_encode($value ?? []);

                continue;
            }

            if (in_array($key, $this->boolColumns[$table] ?? [], true)) {
                $out[$key] = $value ? 1 : 0;

                continue;
            }

            // MySQL DATETIME rejects ISO-8601 (…T…Z); normalize common client timestamps.
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1) {
                try {
                    $out[$key] = Carbon::parse($value)->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    $out[$key] = $value;
                }

                continue;
            }

            $out[$key] = $value;
        }

        if ($table === 'users' && array_key_exists('role', $out)) {
            $role = (string) $out['role'];
            $allowedRoles = ['Admin', 'Manager', 'Accountant', 'Steward', 'Staff'];
            if (! in_array($role, $allowedRoles, true)) {
                throw new \InvalidArgumentException('Invalid user role');
            }
        }

        if (array_key_exists('password', $out) && $out['password'] !== null && $out['password'] !== '') {
            $plain = (string) $out['password'];
            if (! Hash::isHashed($plain)) {
                $out['password'] = Hash::make($plain);
            }
        } elseif (array_key_exists('password', $out) && ($out['password'] === null || $out['password'] === '')) {
            unset($out['password']);
        }

        if ($table === 'system_settings') {
            if (empty($out['key'])) {
                throw new \InvalidArgumentException('system_settings.key is required');
            }
            // ConvertEmptyStringsToNull / missing value must never violate NOT NULL
            if (! array_key_exists('value', $out) || $out['value'] === null) {
                $out['value'] = '';
            } else {
                $out['value'] = (string) $out['value'];
            }
            if ($isInsert && ! isset($out['created_at'])) {
                $out['created_at'] = now();
            }
            $out['updated_at'] = now();

            return $out;
        }

        if ($isInsert && empty($out['id'])) {
            $out['id'] = (string) Str::uuid();
        }

        if ($isInsert && in_array('created_at', $columns, true) && empty($out['created_at'])) {
            $out['created_at'] = now();
        }

        if (in_array('updated_at', $columns, true)) {
            $out['updated_at'] = now();
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function decodeRow(string $table, array $row): array
    {
        unset($row['password']);

        foreach ($this->jsonColumns[$table] ?? [] as $col) {
            if (! array_key_exists($col, $row)) {
                continue;
            }
            if (is_string($row[$col]) && $row[$col] !== '') {
                $decoded = json_decode($row[$col], true);
                $row[$col] = $decoded === null ? $row[$col] : $decoded;
            } elseif ($row[$col] === null) {
                $row[$col] = [];
            }
        }

        foreach ($this->boolColumns[$table] ?? [] as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = (bool) $row[$col];
            }
        }

        // system_settings stores arbitrary text in `value` — never coerce to numbers
        if ($table === 'system_settings') {
            if (array_key_exists('value', $row) && $row['value'] !== null) {
                $row['value'] = (string) $row['value'];
            }
            if (array_key_exists('key', $row) && $row['key'] !== null) {
                $row['key'] = (string) $row['key'];
            }

            return $row;
        }

        foreach ($row as $key => $value) {
            if (is_numeric($value) && ! is_bool($value) && ! str_contains((string) $key, 'phone') && ! str_contains((string) $key, 'code') && $key !== 'billNumber' && $key !== 'bill_number' && $key !== 'reference_no' && $key !== 'po_number' && $key !== 'quotation_number' && $key !== 'invoice_number' && $key !== 'empNo' && $key !== 'username' && $key !== 'id' && $key !== 'key' && $key !== 'value') {
                // Keep money/qty numeric for the SPA; leave ids/codes as strings when already string.
                if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value)) {
                    $row[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
                }
            }
        }

        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    private function shapeResult(array $rows, bool $single, bool $maybeSingle): mixed
    {
        if ($single) {
            if (count($rows) !== 1) {
                return [
                    'data' => null,
                    '__error' => [
                        'message' => 'JSON object requested, multiple (or no) rows returned',
                        'code' => 'PGRST116',
                    ],
                ];
            }

            return $rows[0];
        }

        if ($maybeSingle) {
            return $rows[0] ?? null;
        }

        return $rows;
    }
}
