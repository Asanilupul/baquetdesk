<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Writes the per-company audit trail (who changed what, and when).
 * Logging failures never block the business write that triggered them.
 */
class AuditLogger
{
    /** @var list<string> */
    private const IGNORED_FIELDS = ['created_at', 'updated_at', 'company_id'];

    /** @var list<string> */
    private const MASKED_FIELDS = ['password', 'api_token', 'public_menu_token'];

    /** @var list<string> */
    private const LABEL_FIELDS = [
        'name', 'customer_name', 'bill_number', 'invoice_number', 'quotation_number', 'username',
        'vendor_name', 'product_name', 'account_name', 'voucher_no', 'reference_no', 'key', 'function_date',
    ];

    private static ?bool $tableExists = null;

    /**
     * @param  array{user_id?: string, company_id?: string, role?: string, username?: string}|null  $session
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(
        ?array $session,
        string $companyId,
        string $action,
        ?string $table = null,
        ?string $recordId = null,
        ?string $recordLabel = null,
        ?array $changes = null,
        ?string $ipAddress = null,
    ): void {
        try {
            if (self::$tableExists === null) {
                self::$tableExists = Schema::hasTable('audit_logs');
            }
            if (! self::$tableExists) {
                return;
            }

            DB::table('audit_logs')->insert([
                'company_id' => $companyId !== '' ? $companyId : null,
                'user_id' => ($session['user_id'] ?? '') !== '' ? $session['user_id'] : null,
                'username' => ($session['username'] ?? '') !== '' ? $session['username'] : 'public',
                'role' => ($session['role'] ?? '') !== '' ? $session['role'] : null,
                'action' => $action,
                'table_name' => $table,
                'record_id' => $recordId !== null ? mb_substr($recordId, 0, 255) : null,
                'record_label' => $recordLabel !== null ? mb_substr($recordLabel, 0, 255) : null,
                'changes' => $changes !== null && $changes !== [] ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit log write failed', ['action' => $action, 'table' => $table, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Field-level differences between two versions of a row.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $newValue) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }
            $oldValue = $before[$field] ?? null;
            if (self::normalize($oldValue) === self::normalize($newValue)) {
                continue;
            }
            $changes[$field] = [
                'old' => self::present($field, $oldValue),
                'new' => self::present($field, $newValue),
            ];
        }

        return $changes;
    }

    /**
     * Snapshot of a created or deleted row, without noise and secrets.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function snapshot(array $row): array
    {
        $out = [];
        foreach ($row as $field => $value) {
            if (in_array($field, self::IGNORED_FIELDS, true) || $value === null || $value === '') {
                continue;
            }
            $out[$field] = self::present($field, $value);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function labelFor(array $row): ?string
    {
        foreach (self::LABEL_FIELDS as $field) {
            $value = $row[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private static function present(string $field, mixed $value): mixed
    {
        if (in_array($field, self::MASKED_FIELDS, true)) {
            return $value === null || $value === '' ? null : '********';
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500).'…';
        }

        return $value;
    }

    private static function normalize(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }
        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        return (string) ($value ?? '');
    }
}
