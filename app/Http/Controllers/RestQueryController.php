<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class RestQueryController extends Controller
{
    /** @var list<string> */
    private array $allowedTables = [
        'users', 'employees', 'stewards', 'attendance', 'advance_requests', 'payments',
        'functions', 'walking_inquiries', 'quotations', 'invoices', 'suppliers',
        'supplier_products', 'purchase_orders', 'supplier_payments', 'menu_categories',
        'menu_items', 'menus', 'menu_hall_prices', 'menu_category_configs', 'menu_selections',
        'function_menu_selections', 'menu_addons', 'function_menu_addons', 'function_menu_extras', 'kitchen_sheets',
        'store_items', 'item_recipes', 'store_transactions', 'vendors', 'vendor_categories',
        'vendor_packages', 'accounts_coa', 'journal_entries', 'function_sheets',
        'system_settings', 'production_balancing', 'halls', 'menu_extras', 'combo_packages',
    ];

    /** @var array<string, list<string>> */
    private array $jsonColumns = [
        'quotations' => ['items'],
        'invoices' => ['items'],
        'purchase_orders' => ['items'],
        'kitchen_sheets' => ['selected_items', 'bites', 'soft_drinks'],
        'vendors' => ['pictures', 'packages'],
        'vendor_packages' => ['categories'],
        'function_sheets' => ['sheet_data'],
        'production_balancing' => ['in_store_items', 'processing_items', 'daily_sales'],
        'combo_packages' => ['vendor_package_ids', 'bite_lines', 'softdrink_lines'],
    ];

    /** @var array<string, list<string>> */
    private array $boolColumns = [
        'stewards' => ['isPaid'],
        'advance_requests' => ['is_paid', 'is_reconciled'],
        'payments' => ['is_reconciled', 'is_refunded'],
    ];

    public function handle(Request $request): JsonResponse
    {
        try {
            $table = (string) $request->input('table', '');
            if (! in_array($table, $this->allowedTables, true) || ! Schema::hasTable($table)) {
                return response()->json([
                    'data' => null,
                    'error' => ['message' => "Unknown or disallowed table: {$table}"],
                ], 400);
            }

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

            $result = match ($action) {
                'select' => $this->runSelect($table, $select, $filters, $order, $limit, $single, $maybeSingle),
                'insert' => $this->runInsert($table, $payload, $returnRows || $single || $maybeSingle, $single, $maybeSingle),
                'update' => $this->runUpdate($table, $payload, $filters, $returnRows || $single || $maybeSingle, $single, $maybeSingle),
                'delete' => $this->runDelete($table, $filters),
                'upsert' => $this->runUpsert($table, $payload, $onConflict, $returnRows || $single || $maybeSingle, $single, $maybeSingle),
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

    private function runSelect(
        string $table,
        mixed $select,
        mixed $filters,
        mixed $order,
        mixed $limit,
        bool $single,
        bool $maybeSingle
    ): mixed {
        $query = DB::table($table);
        $this->applyFilters($query, $filters);

        if (is_string($select) && $select !== '*') {
            $cols = array_map('trim', explode(',', $select));
            $query->select($cols);
        }

        if (is_array($order) && ! empty($order['column'])) {
            $query->orderBy($order['column'], ($order['ascending'] ?? true) ? 'asc' : 'desc');
        }

        if ($limit !== null && $limit !== '') {
            $query->limit((int) $limit);
        }

        $rows = $query->get()->map(fn ($row) => $this->decodeRow($table, (array) $row))->all();

        return $this->shapeResult($rows, $single, $maybeSingle);
    }

    private function runInsert(
        string $table,
        mixed $payload,
        bool $returnRows,
        bool $single,
        bool $maybeSingle
    ): mixed {
        $rows = $this->normalizeRows($payload);
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = $this->prepareWriteRow($table, $row, true);
        }

        DB::table($table)->insert($prepared);

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
        bool $maybeSingle
    ): mixed {
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Update payload must be an object');
        }

        $query = DB::table($table);
        $this->applyFilters($query, $filters);

        $update = $this->prepareWriteRow($table, $payload, false);
        unset($update['id'], $update['key']);

        if ($table !== 'system_settings') {
            $update['updated_at'] = now();
        } else {
            $update['updated_at'] = now();
        }

        $query->update($update);

        if (! $returnRows) {
            return null;
        }

        $rows = DB::table($table);
        $this->applyFilters($rows, $filters);
        $decoded = $rows->get()->map(fn ($row) => $this->decodeRow($table, (array) $row))->all();

        return $this->shapeResult($decoded, $single, $maybeSingle);
    }

    private function runDelete(string $table, mixed $filters): mixed
    {
        $query = DB::table($table);
        $this->applyFilters($query, $filters);
        $query->delete();

        return null;
    }

    private function runUpsert(
        string $table,
        mixed $payload,
        mixed $onConflict,
        bool $returnRows,
        bool $single,
        bool $maybeSingle
    ): mixed {
        $rows = $this->normalizeRows($payload);
        $conflictCols = array_values(array_filter(array_map('trim', explode(',', (string) ($onConflict ?: 'id')))));
        $saved = [];

        foreach ($rows as $row) {
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
                $saved[] = $this->decodeRow($table, (array) $fresh->first());
            } else {
                DB::table($table)->insert($prepared);
                $saved[] = $this->decodeRow($table, $prepared);
            }
        }

        if (! $returnRows) {
            return null;
        }

        return $this->shapeResult($saved, $single, $maybeSingle);
    }

    private function applyFilters($query, mixed $filters): void
    {
        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $filter) {
            if (! is_array($filter) || empty($filter['column'])) {
                continue;
            }

            $column = $filter['column'];
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
        $columns = Schema::getColumnListing($table);
        $out = [];

        foreach ($row as $key => $value) {
            if (! in_array($key, $columns, true)) {
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

            $out[$key] = $value;
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
            if (is_numeric($value) && ! is_bool($value) && ! str_contains((string) $key, 'phone') && ! str_contains((string) $key, 'code') && $key !== 'billNumber' && $key !== 'bill_number' && $key !== 'reference_no' && $key !== 'po_number' && $key !== 'quotation_number' && $key !== 'invoice_number' && $key !== 'empNo' && $key !== 'username' && $key !== 'password' && $key !== 'id' && $key !== 'key' && $key !== 'value') {
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
