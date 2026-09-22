<?php

namespace Tests\Feature;

use App\Support\LedgerService;
use App\Support\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $cashId;

    private string $revenueId;

    private string $expenseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();

        if (Schema::hasTable('companies')) {
            $row = [
                'id' => $this->companyId,
                'name' => 'GL Test Co',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $cols = Schema::getColumnListing('companies');
            DB::table('companies')->insert(array_intersect_key($row, array_flip($cols)));
        }

        $this->cashId = $this->seedAccount('1000', 'Cash on Hand', 'Asset');
        $this->revenueId = $this->seedAccount('4000', 'Service Income', 'Revenue');
        $this->expenseId = $this->seedAccount('5000', 'Office Expense', 'Expense');
    }

    private function seedAccount(string $code, string $name, string $type): string
    {
        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            'company_id' => $this->companyId,
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'balance' => 0,
            'detail_type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $cols = Schema::getColumnListing('accounts_coa');
        DB::table('accounts_coa')->insert(array_intersect_key($row, array_flip($cols)));

        return $id;
    }

    public function test_post_balanced_voucher_updates_coa_balances(): void
    {
        $result = LedgerService::postVoucher($this->companyId, [
            'voucher_date' => '2026-09-22',
            'reference_no' => 'JV-TEST-1',
            'memo' => 'Sale',
            'source' => 'manual',
        ], [
            [
                'account_id' => $this->cashId,
                'debit' => 1000,
                'credit' => 0,
                'description' => 'Cash in',
            ],
            [
                'account_id' => $this->revenueId,
                'debit' => 0,
                'credit' => 1000,
                'description' => 'Revenue',
            ],
        ]);

        $this->assertSame('posted', $result['voucher']['status']);
        $this->assertCount(2, $result['lines']);

        $cash = DB::table('accounts_coa')->where('id', $this->cashId)->first();
        $rev = DB::table('accounts_coa')->where('id', $this->revenueId)->first();
        $this->assertEquals(1000.0, (float) $cash->balance);
        $this->assertEquals(1000.0, (float) $rev->balance);
    }

    public function test_unbalanced_voucher_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unbalanced');

        LedgerService::postVoucher($this->companyId, [
            'voucher_date' => '2026-09-22',
            'reference_no' => 'JV-BAD',
            'source' => 'manual',
        ], [
            ['account_id' => $this->cashId, 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->revenueId, 'debit' => 0, 'credit' => 50],
        ]);
    }

    public function test_void_reverses_balances(): void
    {
        $posted = LedgerService::postVoucher($this->companyId, [
            'voucher_date' => '2026-09-22',
            'reference_no' => 'JV-VOID',
            'source' => 'manual',
        ], [
            ['account_id' => $this->expenseId, 'debit' => 250, 'credit' => 0],
            ['account_id' => $this->cashId, 'debit' => 0, 'credit' => 250],
        ]);

        $voucherId = $posted['voucher']['id'];
        LedgerService::voidVoucher($this->companyId, $voucherId);

        $cash = DB::table('accounts_coa')->where('id', $this->cashId)->first();
        $exp = DB::table('accounts_coa')->where('id', $this->expenseId)->first();
        $this->assertEquals(0.0, (float) $cash->balance);
        $this->assertEquals(0.0, (float) $exp->balance);

        $status = DB::table('journal_vouchers')->where('id', $voucherId)->value('status');
        $this->assertSame('void', $status);
    }

    public function test_expense_style_voucher_is_balanced(): void
    {
        $result = LedgerService::postVoucher($this->companyId, [
            'voucher_date' => '2026-09-22',
            'reference_no' => 'EXP-123',
            'source' => 'expense',
            'memo' => 'Office supplies',
        ], [
            ['account_id' => $this->expenseId, 'debit' => 75.5, 'credit' => 0],
            ['account_id' => $this->cashId, 'debit' => 0, 'credit' => 75.5],
        ]);

        $this->assertEquals(75.5, (float) $result['voucher']['total_debit']);
        $this->assertEquals(75.5, (float) $result['voucher']['total_credit']);

        $tbDebit = (float) DB::table('journal_entries')->where('voucher_id', $result['voucher']['id'])->sum('debit');
        $tbCredit = (float) DB::table('journal_entries')->where('voucher_id', $result['voucher']['id'])->sum('credit');
        $this->assertEqualsWithDelta($tbDebit, $tbCredit, 0.001);
    }

    public function test_rebuild_balances_matches_posted_lines(): void
    {
        LedgerService::postVoucher($this->companyId, [
            'voucher_date' => '2026-09-22',
            'reference_no' => 'JV-RB',
            'source' => 'manual',
        ], [
            ['account_id' => $this->cashId, 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->revenueId, 'debit' => 0, 'credit' => 500],
        ]);

        DB::table('accounts_coa')->where('id', $this->cashId)->update(['balance' => 9999]);

        $count = LedgerService::rebuildBalances($this->companyId);
        $this->assertGreaterThan(0, $count);

        $cash = DB::table('accounts_coa')->where('id', $this->cashId)->first();
        $this->assertEquals(500.0, (float) $cash->balance);
    }

    public function test_payment_ledger_posts_cash_and_customer_deposits(): void
    {
        $paymentId = (string) Str::uuid();
        PaymentLedger::sync($this->companyId, [
            'id' => $paymentId,
            'bill_amount' => 1200,
            'bill_date' => '2026-09-22',
            'bill_number' => 'B-1',
            'payment_type' => 'Advance',
        ]);

        $cash = DB::table('accounts_coa')
            ->where('company_id', $this->companyId)
            ->where('account_code', PaymentLedger::CASH_CODE)
            ->first();
        $deposit = DB::table('accounts_coa')
            ->where('company_id', $this->companyId)
            ->where('account_code', PaymentLedger::DEPOSIT_CODE)
            ->first();

        $this->assertNotNull($cash);
        $this->assertNotNull($deposit);
        $this->assertEquals(1200.0, (float) $cash->balance);
        $this->assertEquals(1200.0, (float) $deposit->balance);

        $voucher = DB::table('journal_vouchers')
            ->where('reference_no', PaymentLedger::referenceFor($paymentId))
            ->where('status', 'posted')
            ->first();
        $this->assertNotNull($voucher);
        $this->assertEquals(1200.0, (float) $voucher->total_debit);
        $this->assertEquals(1200.0, (float) $voucher->total_credit);
    }
}
