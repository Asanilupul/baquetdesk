<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Support\ApiPermissions;
use App\Support\ComboPricing;
use App\Support\CompanyApiSession;
use App\Support\CompanySubscription;
use App\Support\LedgerService;
use App\Support\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class RestQueryController extends Controller
{
    /** @var array<string, list<string>> */
    private static array $columnCache = [];

    /** @var array{user_id: string, company_id: string, role: string, username: string}|null */
    private ?array $auditSession = null;

    private ?string $auditIp = null;

    private ?string $currentToken = null;

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
        'journal_vouchers' => ['column' => 'voucher_date', 'ascending' => false],
        'invoices' => ['column' => 'created_at', 'ascending' => false],
        'halls' => ['column' => 'name', 'ascending' => true],
        'function_types' => ['column' => 'name', 'ascending' => true],
        'meal_types' => ['column' => 'start_time', 'ascending' => true],
        'menu_extras' => ['column' => 'name', 'ascending' => true],
        'combo_packages' => ['column' => 'name', 'ascending' => true],
        'payroll_runs' => ['column' => 'period', 'ascending' => false],
        'payroll_slips' => ['column' => 'run_id', 'ascending' => false],
    ];

    /** @var list<string> */
    private array $allowedTables = [
        'users', 'employees', 'stewards', 'attendance', 'advance_requests', 'payments',
        'functions', 'walking_inquiries', 'quotations', 'invoices', 'suppliers',
        'supplier_products', 'purchase_orders', 'supplier_payments', 'menu_categories',
        'menu_items', 'menus', 'menu_hall_prices', 'menu_category_configs', 'menu_selections',
        'function_menu_selections', 'menu_addons', 'function_menu_addons', 'function_menu_extras', 'kitchen_sheets',
        'store_items', 'item_recipes', 'store_transactions', 'vendors', 'vendor_categories',
        'vendor_packages', 'accounts_coa', 'journal_entries', 'expense_entries', 'journal_vouchers', 'function_sheets',
        'system_settings', 'production_balancing', 'halls', 'function_types', 'meal_types', 'menu_extras', 'combo_packages', 'companies',
        'payroll_runs', 'payroll_slips',
    ];

    /** @var array<string, list<string>> */
    private array $jsonColumns = [
        'users' => ['allowed_modules'],
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
        'payroll_runs' => ['totals'],
        'payroll_slips' => ['employee_snapshot', 'components'],
    ];

    /** @var array<string, list<string>> */
    private array $boolColumns = [
        'stewards' => ['isPaid'],
        'advance_requests' => ['is_paid', 'is_reconciled'],
        'payments' => ['is_reconciled', 'is_refunded'],
        'accounts_coa' => ['is_active'],
        'employees' => ['is_epf_employee'],
    ];

    public function handle(Request $request): JsonResponse
    {
        try {
            $action = (string) $request->input('action', 'select');

            // Core GL voucher actions (do not require a normal table CRUD path)
            if (in_array($action, ['post_journal_voucher', 'void_journal_voucher'], true)) {
                return $this->handleLedgerAction($request, $action);
            }

            $table = (string) $request->input('table', '');
            if (! in_array($table, $this->allowedTables, true)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => "Unknown or disallowed table: {$table}"],
                ], 400);
            }

            // Core GL: journal lines/vouchers are write-only via LedgerService actions
            if (in_array($table, ['journal_entries', 'journal_vouchers'], true)
                && in_array($action, ['insert', 'update', 'delete', 'upsert'], true)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Use post_journal_voucher or void_journal_voucher. Direct journal writes are not allowed.'],
                ], 422);
            }

            $filters = $request->input('filters', []);
            $order = $request->input('order');
            $limit = $request->input('limit');
            $single = (bool) $request->input('single', false);
            $maybeSingle = (bool) $request->input('maybeSingle', false);
            $select = $request->input('select', '*');
            $payload = $request->input('payload');
            $onConflict = $request->input('onConflict');
            $returnRows = (bool) $request->input('returnRows', false);

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

            $session = CompanyApiSession::fromRequest($request);

            // Public vendor self-registration is the only unauthenticated write allowed
            if ($session === null && $table === 'vendors' && $action === 'insert') {
                return $this->handlePublicVendorRegister($request, $payload, $returnRows || $single || $maybeSingle, $single, $maybeSingle);
            }

            if ($session === null) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Authentication required. Please log in again.'],
                ], 401);
            }

            if ($session['role'] === 'Vendor') {
                return $this->handleVendorSession($request, $session, $table, $action, $payload, $filters, $single, $maybeSingle, $returnRows);
            }

            // Token company is authoritative; X-Company-Id is never trusted on its own
            $companyId = $session['company_id'];
            if ($companyId === '') {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Company session required. Please log in again.'],
                ], 401);
            }

            $actor = ApiPermissions::actor($session);
            if ($actor === null) {
                CompanyApiSession::revoke(CompanyApiSession::tokenFromRequest($request));

                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Your session is no longer valid. Please log in again.'],
                ], 401);
            }
            if (! ApiPermissions::isStaff($actor)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Your role does not have access to company data.'],
                ], 403);
            }

            $isWrite = in_array($action, ['insert', 'update', 'delete', 'upsert'], true);
            if ($isWrite && $table === 'users' && $actor['role'] !== 'Admin') {
                // Non-admins may only edit their own profile (never other accounts)
                if ($action !== 'update') {
                    return response()->json([
                        'data' => null,
                        'error' => ['message' => 'Only Admins can create or delete user accounts'],
                    ], 403);
                }
                $filters = array_merge(is_array($filters) ? $filters : [], [
                    ['column' => 'id', 'op' => 'eq', 'value' => $actor['id']],
                ]);
            } elseif ($isWrite && ! ApiPermissions::canWriteTable($actor, $table)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'You do not have permission to change '.str_replace('_', ' ', $table).'.'],
                ], 403);
            }

            if (in_array($table, ['companies'], true) && in_array($action, ['insert', 'delete', 'upsert'], true)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Company records cannot be created or deleted via this API'],
                ], 403);
            }

            if ($deny = $this->denyIfCompanyUnusable($companyId)) {
                return $deny;
            }

            // Only Admins may create/change user roles or module access
            if ($table === 'users' && in_array($action, ['insert', 'update', 'upsert'], true)) {
                if ($actor['role'] !== 'Admin') {
                    $payloadRows = is_array($payload) ? (array_is_list($payload) ? $payload : [$payload]) : [];
                    foreach ($payloadRows as $row) {
                        if (is_array($row) && (array_key_exists('role', $row) || array_key_exists('allowed_modules', $row))) {
                            return response()->json([
                                'data' => null,
                                'error' => ['message' => 'Only Admins can manage user roles and module access'],
                            ], 403);
                        }
                    }
                }
            }

            $this->auditSession = $session;
            $this->auditIp = $request->ip();
            $this->currentToken = CompanyApiSession::tokenFromRequest($request);

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
            return $this->errorResponse($e, 'Data API request');
        }
    }

    public function bootstrap(Request $request): JsonResponse
    {
        try {
            $started = microtime(true);
            $attendanceStart = (string) $request->input('attendance_start', '');
            $attendanceEnd = (string) $request->input('attendance_end', '');

            $session = CompanyApiSession::fromRequest($request);
            if ($session === null) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Authentication required. Please log in again.'],
                ], 401);
            }

            if ($session['role'] === 'Vendor') {
                return response()->json([
                    'data' => $this->vendorBootstrapPayload($session),
                    'error' => null,
                    'meta' => ['ms' => (int) round((microtime(true) - $started) * 1000), 'scope' => 'vendor'],
                ]);
            }

            $companyId = $session['company_id'];
            if ($companyId === '') {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Company session required. Please log in again.'],
                ], 401);
            }

            $actor = ApiPermissions::actor($session);
            if ($actor === null) {
                CompanyApiSession::revoke(CompanyApiSession::tokenFromRequest($request));

                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Your session is no longer valid. Please log in again.'],
                ], 401);
            }
            if (! ApiPermissions::isStaff($actor)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'Your role does not have access to company data.'],
                ], 403);
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
                    $bootstrapErrors[$table] = 'Could not load (ref '.$this->logFailure($e, 'Bootstrap table '.$table).')';
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
                $settings = collect($this->settingsRowsForCompany($companyId))
                    ->map(function (array $arr) {
                        if (($arr['key'] ?? '') === 'company_logo') {
                            $arr['value'] = '';
                            $arr['deferred'] = true;
                        }

                        return $arr;
                    })
                    ->all();
            }
            $payload['system_settings'] = $settings;
            $payload['companies'] = $companyId !== '' && Schema::hasTable('companies')
                ? DB::table('companies')->where('id', $companyId)->get()->map(fn ($r) => (array) $r)->all()
                : [];
            $payload['public_menu_token'] = $this->ensurePublicMenuToken($companyId);

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
            return $this->errorResponse($e, 'Data API request');
        }
    }

    private function ensurePublicMenuToken(string $companyId): string
    {
        if ($companyId === '' || ! Schema::hasColumn('companies', 'public_menu_token')) {
            return '';
        }

        $token = (string) (DB::table('companies')->where('id', $companyId)->value('public_menu_token') ?? '');
        if ($token === '') {
            $token = Str::random(40);
            DB::table('companies')->where('id', $companyId)->update(['public_menu_token' => $token]);
        }

        return $token;
    }

    /**
     * Unauthenticated vendor sign-up: one row, never attached to a company, never privileged.
     */
    private function handlePublicVendorRegister(Request $request, mixed $payload, bool $returnRows, bool $single, bool $maybeSingle): JsonResponse
    {
        $limiterKey = 'public-vendor-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Too many vendor registrations from this network. Try again in '.ceil(RateLimiter::availableIn($limiterKey) / 60).' minutes.'],
            ], 429);
        }
        RateLimiter::hit($limiterKey, 3600);

        $rows = is_array($payload) ? $this->normalizeRows($payload) : [];
        if (count($rows) !== 1) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Register one vendor at a time.'],
            ], 422);
        }

        $row = $rows[0];
        $username = trim((string) ($row['username'] ?? ''));
        $password = (string) ($row['password'] ?? '');
        if ($username === '' || strlen($password) < 8 || trim((string) ($row['vendor_name'] ?? '')) === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Vendor name, username and a password of at least 8 characters are required.'],
            ], 422);
        }

        $taken = DB::table('users')->where('username', $username)->exists()
            || DB::table('vendors')->where('username', $username)->exists();
        if ($taken) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'That username is already taken.'],
            ], 422);
        }

        unset($row['id'], $row['company_id'], $row['status'], $row['created_at'], $row['updated_at']);
        $row['username'] = $username;
        $row['status'] = 'Active';

        $prepared = $this->prepareWriteRow('vendors', $row, true);
        if (Schema::hasColumn('vendors', 'company_id')) {
            $prepared['company_id'] = null;
        }
        DB::table('vendors')->insert($prepared);

        if (! $returnRows) {
            return response()->json(['data' => null, 'error' => null]);
        }

        $result = $this->shapeResult([$this->decodeRow('vendors', $prepared)], $single, $maybeSingle);

        return response()->json(['data' => $result, 'error' => null]);
    }

    /**
     * Vendor portal sessions may only read / edit their own vendor profile and read vendor categories.
     *
     * @param  array{user_id: string, company_id: string, role: string, username: string}  $session
     */
    private function handleVendorSession(
        Request $request,
        array $session,
        string $table,
        string $action,
        mixed $payload,
        mixed $filters,
        bool $single,
        bool $maybeSingle,
        bool $returnRows
    ): JsonResponse {
        $vendorId = $session['user_id'];
        $vendor = $vendorId !== '' ? DB::table('vendors')->where('id', $vendorId)->first() : null;
        if (! $vendor || ! $this->vendorIsActive($vendor)) {
            CompanyApiSession::revoke(CompanyApiSession::tokenFromRequest($request));

            return response()->json([
                'data' => null,
                'error' => ['message' => 'Your vendor session is no longer valid. Please log in again.'],
            ], 401);
        }

        $deny = fn () => response()->json([
            'data' => null,
            'error' => ['message' => 'Vendor accounts can only manage their own profile.'],
        ], 403);

        $vendorCompany = trim((string) ($vendor->company_id ?? ''));
        $ownFilter = [['column' => 'id', 'op' => 'eq', 'value' => $vendorId]];
        $filters = array_merge(is_array($filters) ? $filters : [], $ownFilter);

        if ($table === 'vendor_categories' && $action === 'select') {
            $result = $this->runSelect($table, '*', [], null, null, $single, $maybeSingle, $vendorCompany);
        } elseif ($table === 'vendors' && $action === 'select') {
            $result = $this->runSelect($table, '*', $filters, null, null, $single, $maybeSingle, $vendorCompany);
        } elseif ($table === 'vendors' && $action === 'update' && is_array($payload)) {
            unset($payload['id'], $payload['company_id'], $payload['status']);
            $this->auditSession = $session;
            $this->auditIp = $request->ip();
            $this->currentToken = CompanyApiSession::tokenFromRequest($request);
            $result = $this->runUpdate($table, $payload, $filters, $returnRows || $single || $maybeSingle, $single, $maybeSingle, $vendorCompany);
        } else {
            return $deny();
        }

        if (is_array($result) && array_key_exists('__error', $result)) {
            return response()->json(['data' => $result['data'] ?? null, 'error' => $result['__error']]);
        }

        return response()->json(['data' => $result, 'error' => null]);
    }

    /**
     * @param  array{user_id: string, company_id: string, role: string, username: string}  $session
     * @return array<string, mixed>
     */
    private function vendorBootstrapPayload(array $session): array
    {
        $payload = array_fill_keys($this->allowedTables, []);
        $vendor = DB::table('vendors')->where('id', $session['user_id'])->first();
        if (! $vendor || ! $this->vendorIsActive($vendor)) {
            return $payload;
        }

        $vendorCompany = trim((string) ($vendor->company_id ?? ''));
        $payload['vendors'] = [$this->decodeRow('vendors', (array) $vendor)];
        $categories = DB::table('vendor_categories');
        $this->applyCompanyScope($categories, 'vendor_categories', $vendorCompany);
        $payload['vendor_categories'] = $categories->orderBy('name')->get()
            ->map(fn ($row) => $this->decodeRow('vendor_categories', (array) $row))
            ->all();
        $payload['public_menu_token'] = '';

        return $payload;
    }

    private function vendorIsActive(object $vendor): bool
    {
        $status = strtolower(trim((string) ($vendor->status ?? '')));

        return $status === '' || $status === 'active';
    }

    /**
     * Company-specific settings layered over global defaults (backup bookkeeping keys are never exposed).
     *
     * @return list<array<string, mixed>>
     */
    private function settingsRowsForCompany(string $companyId, mixed $filters = []): array
    {
        $hasCompanyColumn = Schema::hasColumn('system_settings', 'company_id');
        $query = DB::table('system_settings')->where('key', 'not like', 'backup%');
        $this->applyFilters($query, $filters, 'system_settings');
        if ($hasCompanyColumn) {
            $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== '') {
                    $q->orWhere('company_id', $companyId);
                }
            });
        }

        $byKey = [];
        foreach ($query->get() as $row) {
            $arr = (array) $row;
            $key = (string) ($arr['key'] ?? '');
            $isCompanyRow = $hasCompanyColumn && (string) ($arr['company_id'] ?? '') !== '';
            if (! isset($byKey[$key]) || $isCompanyRow) {
                $byKey[$key] = $arr;
            }
        }

        return array_values(array_map(fn (array $arr) => $this->decodeRow('system_settings', $arr), $byKey));
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
        if ($table === 'system_settings') {
            return $this->shapeResult($this->settingsRowsForCompany($companyId, $filters), $single, $maybeSingle);
        }

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
            $this->audit('create', $table, $row, $companyId, AuditLogger::snapshot($row));
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

        $before = (clone $query)->get()->map(fn ($row) => (array) $row)->all();

        $query->update($update);

        foreach ($before as $row) {
            $this->audit('update', $table, $row, $companyId, AuditLogger::diff($row, array_merge($row, $update)));
        }

        $credentialsChanged = array_intersect(['password', 'role', 'allowed_modules', 'username', 'status'], array_keys($update)) !== [];
        if (in_array($table, ['users', 'vendors'], true) && $credentialsChanged) {
            foreach ($before as $row) {
                $changed = array_filter(
                    ['password', 'role', 'allowed_modules', 'username', 'status'],
                    fn ($col) => array_key_exists($col, $update) && (string) ($row[$col] ?? '') !== (string) $update[$col]
                );
                if ($changed !== []) {
                    CompanyApiSession::revokeUser((string) ($row['id'] ?? ''), $this->currentToken);
                }
            }
        }

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

        $toDelete = (clone $query)->get()->map(fn ($row) => $this->decodeRow($table, (array) $row))->all();

        $query->delete();

        foreach ($toDelete as $row) {
            $this->afterDomainWrite($table, $row, $companyId, 'delete');
            $this->audit('delete', $table, $row, $companyId, AuditLogger::snapshot($row));
            if (in_array($table, ['users', 'vendors'], true)) {
                CompanyApiSession::revokeUser((string) ($row['id'] ?? ''));
            }
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

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $changes
     */
    private function audit(string $action, string $table, array $row, string $companyId, array $changes): void
    {
        if ($action === 'update' && $changes === []) {
            return;
        }

        $recordId = $row['id'] ?? $row['key'] ?? null;

        AuditLogger::record(
            $this->auditSession,
            $companyId,
            $action,
            $table,
            $recordId !== null ? (string) $recordId : null,
            AuditLogger::labelFor($row),
            $changes,
            $this->auditIp,
        );
    }

    private function handleLedgerAction(Request $request, string $action): JsonResponse
    {
        $session = CompanyApiSession::fromRequest($request);
        if ($session === null) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Authentication required. Please log in again.'],
            ], 401);
        }

        $companyId = $session['company_id'];
        if ($companyId === '' || $session['role'] === 'Vendor') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Company session required. Please log in again.'],
            ], 401);
        }

        $actor = ApiPermissions::actor($session);
        if ($actor === null) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Your session is no longer valid. Please log in again.'],
            ], 401);
        }
        if (! ApiPermissions::canWriteTable($actor, 'journal_vouchers')) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'You do not have permission to post or void journal vouchers.'],
            ], 403);
        }

        if ($deny = $this->denyIfCompanyUnusable($companyId)) {
            return $deny;
        }

        $payload = $request->input('payload', []);
        if (! is_array($payload)) {
            $payload = [];
        }

        try {
            if ($action === 'post_journal_voucher') {
                $header = is_array($payload['header'] ?? null) ? $payload['header'] : $payload;
                $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
                if ($lines === [] && is_array($payload['entries'] ?? null)) {
                    $lines = $payload['entries'];
                }
                if (! isset($header['created_by']) || $header['created_by'] === '') {
                    $header['created_by'] = $session['username'] ?? $session['user'] ?? 'user';
                }
                $result = LedgerService::postVoucher($companyId, $header, $lines);

                AuditLogger::record(
                    $session,
                    $companyId,
                    'post_voucher',
                    'journal_vouchers',
                    (string) (data_get($result, 'voucher.id') ?? ''),
                    (string) (data_get($result, 'voucher.voucher_no') ?? $header['reference_no'] ?? ''),
                    AuditLogger::snapshot(['header' => $header, 'lines' => $lines]),
                    $request->ip(),
                );

                return response()->json(['data' => $result, 'error' => null]);
            }

            $voucherId = (string) ($payload['voucher_id'] ?? $payload['id'] ?? '');
            if ($voucherId === '') {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => 'voucher_id is required'],
                ], 400);
            }
            $result = LedgerService::voidVoucher($companyId, $voucherId);

            AuditLogger::record($session, $companyId, 'void_voucher', 'journal_vouchers', $voucherId, null, null, $request->ip());

            return response()->json(['data' => $result, 'error' => null]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Ledger action', 422);
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
            $this->applyCompanyScope($existing, $table, $companyId);
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
                $this->applyCompanyScope($q, $table, $companyId);
                foreach ($match as $col => $val) {
                    $q->where($col, $val);
                }
                $q->update($update);

                $fresh = DB::table($table);
                $this->applyCompanyScope($fresh, $table, $companyId);
                foreach ($match as $col => $val) {
                    $fresh->where($col, $val);
                }
                $decoded = $this->decodeRow($table, (array) $fresh->first());
                $this->afterDomainWrite($table, $decoded, $companyId, 'update');
                $this->audit('update', $table, (array) $found, $companyId, AuditLogger::diff((array) $found, $decoded));
                $saved[] = $decoded;
            } else {
                DB::table($table)->insert($prepared);
                $decoded = $this->decodeRow($table, $prepared);
                $this->afterDomainWrite($table, $decoded, $companyId, 'insert');
                $this->audit('create', $table, $prepared, $companyId, AuditLogger::snapshot($prepared));
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
            $blocked = ['subscription_plan', 'subscription_expires_at', 'subscription_status', 'status', 'public_menu_token'];
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
            // Clients never receive hashes, so a hash-looking value is treated as a plaintext choice too
            if (strlen($plain) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters');
            }
            $out['password'] = Hash::make($plain);
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
                // allowed_modules: null means unrestricted access (do not coerce to []).
                $row[$col] = $col === 'allowed_modules' ? null : [];
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
