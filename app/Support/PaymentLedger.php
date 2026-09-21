<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Posts customer payment handovers to the GL:
 * Debit Cash (1000) / Credit Customer Deposits (2300).
 */
class PaymentLedger
{
    public const CASH_CODE = '1000';

    public const DEPOSIT_CODE = '2300';

    public static function referenceFor(string $paymentId): string
    {
        return 'PAY-'.$paymentId;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    public static function sync(string $companyId, array $payment): void
    {
        if ($companyId === '' || ! Schema::hasTable('journal_entries') || ! Schema::hasTable('accounts_coa')) {
            return;
        }

        $id = (string) ($payment['id'] ?? '');
        if ($id === '') {
            return;
        }

        $amount = (float) ($payment['bill_amount'] ?? 0);
        self::void($companyId, $id);

        if (abs($amount) < 0.00001) {
            return;
        }

        $cash = self::ensureAccount($companyId, self::CASH_CODE, 'Cash on Hand', 'Asset', 'Cash on hand');
        $deposit = self::ensureAccount($companyId, self::DEPOSIT_CODE, 'Customer Deposits', 'Liability', 'Advances from customers');
        if (! $cash || ! $deposit) {
            return;
        }

        $date = (string) ($payment['bill_date'] ?? $payment['function_date'] ?? date('Y-m-d'));
        $bill = (string) ($payment['bill_number'] ?? '');
        $ptype = (string) ($payment['payment_type'] ?? 'Payment');
        $ref = self::referenceFor($id);
        $now = now();
        $abs = abs($amount);
        $isRefund = $amount < 0 || strcasecmp($ptype, 'Refund') === 0;

        // Positive handover: Dr Cash, Cr Customer Deposits
        // Refund / negative: reverse
        $cashDebit = $isRefund ? 0.0 : $abs;
        $cashCredit = $isRefund ? $abs : 0.0;
        $depDebit = $isRefund ? $abs : 0.0;
        $depCredit = $isRefund ? 0.0 : $abs;

        $rows = [
            [
                'id' => (string) Str::uuid(),
                'entry_date' => $date,
                'reference_no' => $ref,
                'account_id' => $cash->id,
                'account_name' => $cash->account_name,
                'account_type' => $cash->account_type,
                'debit' => $cashDebit,
                'credit' => $cashCredit,
                'description' => trim("{$ptype} {$bill} — Cash"),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'entry_date' => $date,
                'reference_no' => $ref,
                'account_id' => $deposit->id,
                'account_name' => $deposit->account_name,
                'account_type' => $deposit->account_type,
                'debit' => $depDebit,
                'credit' => $depCredit,
                'description' => trim("{$ptype} {$bill} — Customer deposit"),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        if (Schema::hasColumn('journal_entries', 'company_id')) {
            foreach ($rows as &$row) {
                $row['company_id'] = $companyId;
            }
            unset($row);
        }

        DB::table('journal_entries')->insert($rows);

        // Net effect on COA balances (Asset +debit -credit; Liability +credit -debit)
        self::bumpBalance($cash->id, $cashDebit - $cashCredit);
        self::bumpBalance($deposit->id, $depCredit - $depDebit);
    }

    public static function void(string $companyId, string $paymentId): void
    {
        if ($companyId === '' || ! Schema::hasTable('journal_entries')) {
            return;
        }

        $ref = self::referenceFor($paymentId);
        $query = DB::table('journal_entries')->where('reference_no', $ref);
        if (Schema::hasColumn('journal_entries', 'company_id')) {
            $query->where('company_id', $companyId);
        }
        $existing = $query->get();
        if ($existing->isEmpty()) {
            return;
        }

        foreach ($existing as $line) {
            $debit = (float) ($line->debit ?? 0);
            $credit = (float) ($line->credit ?? 0);
            $type = (string) ($line->account_type ?? '');
            if (strcasecmp($type, 'Liability') === 0) {
                self::bumpBalance((string) $line->account_id, -($credit - $debit));
            } else {
                self::bumpBalance((string) $line->account_id, -($debit - $credit));
            }
        }

        $del = DB::table('journal_entries')->where('reference_no', $ref);
        if (Schema::hasColumn('journal_entries', 'company_id')) {
            $del->where('company_id', $companyId);
        }
        $del->delete();
    }

    private static function bumpBalance(string $accountId, float $delta): void
    {
        if ($accountId === '' || abs($delta) < 0.00001 || ! Schema::hasTable('accounts_coa')) {
            return;
        }
        $row = DB::table('accounts_coa')->where('id', $accountId)->first();
        if (! $row) {
            return;
        }
        DB::table('accounts_coa')->where('id', $accountId)->update([
            'balance' => ((float) ($row->balance ?? 0)) + $delta,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return object{id:string,account_name:string,account_type:?string}|null
     */
    private static function ensureAccount(
        string $companyId,
        string $code,
        string $name,
        string $type,
        string $detail
    ): ?object {
        $q = DB::table('accounts_coa')->where('account_code', $code);
        if (Schema::hasColumn('accounts_coa', 'company_id')) {
            $q->where('company_id', $companyId);
        }
        $found = $q->first();
        if ($found) {
            return $found;
        }

        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'balance' => 0,
            'description' => $detail,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('accounts_coa', 'company_id')) {
            $row['company_id'] = $companyId;
        }
        if (Schema::hasColumn('accounts_coa', 'detail_type')) {
            $row['detail_type'] = $detail;
        }
        if (Schema::hasColumn('accounts_coa', 'is_active')) {
            $row['is_active'] = true;
        }
        DB::table('accounts_coa')->insert($row);

        return (object) $row;
    }
}
