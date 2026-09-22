<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Posts customer payment handovers to the GL via LedgerService:
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
        $abs = abs($amount);
        $isRefund = $amount < 0 || strcasecmp($ptype, 'Refund') === 0;

        $cashDebit = $isRefund ? 0.0 : $abs;
        $cashCredit = $isRefund ? $abs : 0.0;
        $depDebit = $isRefund ? $abs : 0.0;
        $depCredit = $isRefund ? 0.0 : $abs;

        if (! Schema::hasTable('journal_vouchers')) {
            return;
        }

        LedgerService::postVoucher($companyId, [
            'voucher_date' => $date,
            'reference_no' => $ref,
            'memo' => trim("{$ptype} {$bill}"),
            'source' => 'payment',
            'source_id' => $id,
            'created_by' => 'system',
        ], [
            [
                'account_id' => (string) $cash->id,
                'account_name' => (string) $cash->account_name,
                'account_type' => (string) $cash->account_type,
                'debit' => $cashDebit,
                'credit' => $cashCredit,
                'description' => trim("{$ptype} {$bill} — Cash"),
                'entry_date' => $date,
            ],
            [
                'account_id' => (string) $deposit->id,
                'account_name' => (string) $deposit->account_name,
                'account_type' => (string) $deposit->account_type,
                'debit' => $depDebit,
                'credit' => $depCredit,
                'description' => trim("{$ptype} {$bill} — Customer deposit"),
                'entry_date' => $date,
            ],
        ]);
    }

    public static function void(string $companyId, string $paymentId): void
    {
        if ($companyId === '' || $paymentId === '') {
            return;
        }
        LedgerService::voidByReference($companyId, self::referenceFor($paymentId));
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
