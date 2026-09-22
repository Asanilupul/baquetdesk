<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * QuickBooks-style Core GL: balanced multi-line vouchers with COA balance sync.
 */
class LedgerService
{
    /**
     * @param  array{
     *   voucher_date?:string,
     *   reference_no?:string,
     *   memo?:string,
     *   source?:string,
     *   source_id?:string|null,
     *   created_by?:string|null,
     *   id?:string
     * }  $header
     * @param  list<array{
     *   account_id:string,
     *   account_name?:string,
     *   account_type?:string,
     *   debit?:float|int|string,
     *   credit?:float|int|string,
     *   description?:string,
     *   entry_date?:string
     * }>  $lines
     * @return array{voucher: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public static function postVoucher(string $companyId, array $header, array $lines): array
    {
        if ($companyId === '') {
            throw new InvalidArgumentException('Company is required to post a journal voucher.');
        }
        if (! Schema::hasTable('journal_vouchers') || ! Schema::hasTable('journal_entries') || ! Schema::hasTable('accounts_coa')) {
            throw new InvalidArgumentException('Ledger tables are not available. Run migrations.');
        }

        $normalized = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $accountId = trim((string) ($line['account_id'] ?? ''));
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if ($accountId === '') {
                continue;
            }
            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Debit and credit amounts must be non-negative.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A journal line cannot have both debit and credit.');
            }
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $account = self::resolveAccount($companyId, $accountId);
            if ($account === null) {
                throw new InvalidArgumentException("Unknown account: {$accountId}");
            }
            if (Schema::hasColumn('accounts_coa', 'is_active') && isset($account->is_active) && ! $account->is_active) {
                throw new InvalidArgumentException("Account {$account->account_name} is inactive.");
            }

            $normalized[] = [
                'account_id' => (string) $account->id,
                'account_name' => (string) ($line['account_name'] ?? $account->account_name),
                'account_type' => (string) ($line['account_type'] ?? $account->account_type ?? ''),
                'debit' => $debit,
                'credit' => $credit,
                'description' => (string) ($line['description'] ?? ''),
                'entry_date' => (string) ($line['entry_date'] ?? $header['voucher_date'] ?? date('Y-m-d')),
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (count($normalized) < 2) {
            throw new InvalidArgumentException('A journal voucher requires at least two lines.');
        }
        if (abs($totalDebit - $totalCredit) > 0.009) {
            throw new InvalidArgumentException(sprintf(
                'Journal is unbalanced. Debits %.2f must equal credits %.2f.',
                $totalDebit,
                $totalCredit
            ));
        }

        $voucherId = (string) ($header['id'] ?? Str::uuid());
        $ref = trim((string) ($header['reference_no'] ?? ''));
        if ($ref === '') {
            $ref = 'JV-'.strtoupper(substr(str_replace('-', '', $voucherId), 0, 8));
        }
        $date = (string) ($header['voucher_date'] ?? date('Y-m-d'));
        $now = now();

        $voucher = [
            'id' => $voucherId,
            'company_id' => $companyId,
            'voucher_date' => $date,
            'reference_no' => $ref,
            'memo' => (string) ($header['memo'] ?? ''),
            'source' => (string) ($header['source'] ?? 'manual'),
            'source_id' => $header['source_id'] ?? null,
            'status' => 'posted',
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'created_by' => $header['created_by'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $lineRows = [];
        foreach ($normalized as $line) {
            $row = [
                'id' => (string) Str::uuid(),
                'voucher_id' => $voucherId,
                'company_id' => $companyId,
                'entry_date' => $line['entry_date'] ?: $date,
                'reference_no' => $ref,
                'account_id' => $line['account_id'],
                'account_name' => $line['account_name'],
                'account_type' => $line['account_type'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $lineRows[] = $row;
        }

        DB::transaction(function () use ($voucher, $lineRows) {
            DB::table('journal_vouchers')->insert(self::filterColumns('journal_vouchers', $voucher));
            foreach ($lineRows as $row) {
                DB::table('journal_entries')->insert(self::filterColumns('journal_entries', $row));
                self::applyLineBalanceDelta($row, 1);
            }
        });

        return [
            'voucher' => $voucher,
            'lines' => $lineRows,
        ];
    }

    /**
     * Void a posted voucher by reversing COA balances and marking status void.
     *
     * @return array{voucher: array<string, mixed>}
     */
    public static function voidVoucher(string $companyId, string $voucherId): array
    {
        if ($companyId === '' || $voucherId === '') {
            throw new InvalidArgumentException('Company and voucher id are required.');
        }

        $voucher = DB::table('journal_vouchers')->where('id', $voucherId);
        if (Schema::hasColumn('journal_vouchers', 'company_id')) {
            $voucher->where('company_id', $companyId);
        }
        $header = $voucher->first();
        if (! $header) {
            throw new InvalidArgumentException('Journal voucher not found.');
        }
        if (($header->status ?? 'posted') === 'void') {
            return ['voucher' => (array) $header];
        }

        $linesQ = DB::table('journal_entries')->where('voucher_id', $voucherId);
        if (Schema::hasColumn('journal_entries', 'company_id')) {
            $linesQ->where('company_id', $companyId);
        }
        $lines = $linesQ->get();

        DB::transaction(function () use ($companyId, $voucherId, $lines) {
            foreach ($lines as $line) {
                self::applyLineBalanceDelta((array) $line, -1);
            }
            $upd = DB::table('journal_vouchers')->where('id', $voucherId);
            if (Schema::hasColumn('journal_vouchers', 'company_id')) {
                $upd->where('company_id', $companyId);
            }
            $upd->update([
                'status' => 'void',
                'updated_at' => now(),
            ]);
        });

        $fresh = DB::table('journal_vouchers')->where('id', $voucherId)->first();

        return ['voucher' => (array) $fresh];
    }

    /**
     * Delete payment-sourced vouchers (used when re-syncing payments).
     */
    public static function voidByReference(string $companyId, string $referenceNo): void
    {
        if ($companyId === '' || $referenceNo === '' || ! Schema::hasTable('journal_vouchers')) {
            return;
        }

        $q = DB::table('journal_vouchers')
            ->where('reference_no', $referenceNo)
            ->where('status', 'posted');
        if (Schema::hasColumn('journal_vouchers', 'company_id')) {
            $q->where('company_id', $companyId);
        }
        foreach ($q->get() as $voucher) {
            try {
                self::voidVoucher($companyId, (string) $voucher->id);
            } catch (Throwable) {
                // fall through to hard delete legacy
            }
            // Hard-delete payment sync rows so re-post can reuse the same reference
            $lines = DB::table('journal_entries')->where('voucher_id', $voucher->id);
            if (Schema::hasColumn('journal_entries', 'company_id')) {
                $lines->where('company_id', $companyId);
            }
            $lines->delete();
            $del = DB::table('journal_vouchers')->where('id', $voucher->id);
            if (Schema::hasColumn('journal_vouchers', 'company_id')) {
                $del->where('company_id', $companyId);
            }
            $del->delete();
        }

        // Legacy lines without voucher (pre-migration)
        if (Schema::hasTable('journal_entries')) {
            $legacy = DB::table('journal_entries')->where('reference_no', $referenceNo)->whereNull('voucher_id');
            if (Schema::hasColumn('journal_entries', 'company_id')) {
                $legacy->where('company_id', $companyId);
            }
            foreach ($legacy->get() as $line) {
                self::applyLineBalanceDelta((array) $line, -1);
            }
            $legacy->delete();
        }
    }

    public static function rebuildBalances(string $companyId = ''): int
    {
        if (! Schema::hasTable('accounts_coa') || ! Schema::hasTable('journal_entries')) {
            return 0;
        }

        $accounts = DB::table('accounts_coa');
        if ($companyId !== '' && Schema::hasColumn('accounts_coa', 'company_id')) {
            $accounts->where('company_id', $companyId);
        }
        $count = 0;
        foreach ($accounts->get() as $account) {
            $lines = DB::table('journal_entries')->where('account_id', $account->id);
            if ($companyId !== '' && Schema::hasColumn('journal_entries', 'company_id')) {
                $lines->where('company_id', $companyId);
            }
            // Only posted vouchers (or legacy null voucher)
            if (Schema::hasTable('journal_vouchers') && Schema::hasColumn('journal_entries', 'voucher_id')) {
                $voidIds = DB::table('journal_vouchers')->where('status', 'void')->pluck('id')->all();
                if ($voidIds !== []) {
                    $lines->where(function ($q) use ($voidIds) {
                        $q->whereNull('voucher_id')->orWhereNotIn('voucher_id', $voidIds);
                    });
                }
            }

            $agg = (clone $lines)->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();
            $debit = (float) ($agg->d ?? 0);
            $credit = (float) ($agg->c ?? 0);
            $type = (string) ($account->account_type ?? '');
            $balance = self::signedBalance($type, $debit, $credit);

            DB::table('accounts_coa')->where('id', $account->id)->update([
                'balance' => round($balance, 2),
                'updated_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function applyLineBalanceDelta(array $line, int $direction): void
    {
        $accountId = (string) ($line['account_id'] ?? '');
        if ($accountId === '') {
            return;
        }
        $debit = (float) ($line['debit'] ?? 0);
        $credit = (float) ($line['credit'] ?? 0);
        $type = (string) ($line['account_type'] ?? '');
        $delta = self::signedBalance($type, $debit, $credit) * $direction;
        if (abs($delta) < 0.00001) {
            return;
        }
        $row = DB::table('accounts_coa')->where('id', $accountId)->first();
        if (! $row) {
            return;
        }
        DB::table('accounts_coa')->where('id', $accountId)->update([
            'balance' => round(((float) ($row->balance ?? 0)) + $delta, 2),
            'updated_at' => now(),
        ]);
    }

    private static function signedBalance(string $type, float $debit, float $credit): float
    {
        $t = strtolower(trim($type));
        // Asset / Expense: debit increases
        if (in_array($t, ['asset', 'expense', 'expenses', 'cost of goods sold'], true)) {
            return $debit - $credit;
        }

        // Liability / Equity / Revenue: credit increases
        return $credit - $debit;
    }

    private static function resolveAccount(string $companyId, string $accountId): ?object
    {
        $q = DB::table('accounts_coa')->where('id', $accountId);
        if (Schema::hasColumn('accounts_coa', 'company_id') && $companyId !== '') {
            $q->where('company_id', $companyId);
        }

        return $q->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function filterColumns(string $table, array $row): array
    {
        if (! Schema::hasTable($table)) {
            return $row;
        }
        $cols = Schema::getColumnListing($table);

        return array_intersect_key($row, array_flip($cols));
    }
}
