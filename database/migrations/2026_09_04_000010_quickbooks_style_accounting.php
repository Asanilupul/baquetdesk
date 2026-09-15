<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts_coa')) {
            Schema::table('accounts_coa', function (Blueprint $table) {
                if (! Schema::hasColumn('accounts_coa', 'detail_type')) {
                    $table->string('detail_type')->nullable()->after('account_type');
                }
                if (! Schema::hasColumn('accounts_coa', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('description');
                }
            });
        }

        if (! Schema::hasTable('expense_entries')) {
            Schema::create('expense_entries', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('expense_date')->nullable();
                $table->string('payee')->nullable();
                $table->string('category_account_id')->nullable();
                $table->string('category_account_name')->nullable();
                $table->string('payment_account_id')->nullable();
                $table->string('payment_account_name')->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('payment_method')->nullable();
                $table->string('reference_no')->nullable();
                $table->text('description')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        $now = now();
        $seed = [
            // Assets (QuickBooks-style)
            ['1000', 'Cash on Hand', 'Asset', 'Cash on hand', 'Petty and till cash'],
            ['1010', 'Petty Cash', 'Asset', 'Cash on hand', 'Small cash float'],
            ['1050', 'Bank Account', 'Asset', 'Bank', 'Operating bank account'],
            ['1100', 'Accounts Receivable (A/R)', 'Asset', 'Accounts Receivable (A/R)', 'Amounts customers owe'],
            ['1200', 'Inventory Asset', 'Asset', 'Other Current Assets', 'Food and beverage stock'],
            ['1500', 'Furniture and Equipment', 'Asset', 'Fixed Assets', 'Hall furniture and equipment'],

            // Liabilities
            ['2000', 'Accounts Payable (A/P)', 'Liability', 'Accounts Payable (A/P)', 'Amounts owed to suppliers'],
            ['2100', 'Credit Card', 'Liability', 'Credit Card', 'Business credit cards'],
            ['2200', 'Accrued Expenses', 'Liability', 'Other Current Liabilities', 'Expenses incurred not yet paid'],
            ['2300', 'Customer Deposits', 'Liability', 'Other Current Liabilities', 'Advances from customers'],

            // Equity
            ['3000', 'Opening Balance Equity', 'Equity', 'Opening Balance Equity', 'Opening balances'],
            ["3100", "Owner's Equity", 'Equity', "Owner's Equity", 'Owner capital'],
            ['3200', 'Retained Earnings', 'Equity', 'Retained Earnings', 'Accumulated profits'],

            // Income
            ['4000', 'Catering Sales', 'Revenue', 'Service/Fee Income', 'Banquet and catering sales'],
            ['4100', 'Hall Rental Income', 'Revenue', 'Service/Fee Income', 'Hall hire income'],
            ['4200', 'Services Income', 'Revenue', 'Service/Fee Income', 'Additional services'],
            ['4300', 'Other Income', 'Revenue', 'Other Primary Income', 'Miscellaneous income'],

            // Cost of Goods Sold
            ['4500', 'Cost of Goods Sold', 'Expense', 'Supplies & Materials - COGS', 'Direct cost of food and beverage'],
            ['4510', 'Food & Beverage Cost', 'Expense', 'Supplies & Materials - COGS', 'Kitchen raw materials'],

            // Expense categories (QuickBooks-style)
            ['5000', 'Advertising & Marketing', 'Expense', 'Advertising/Promotional', 'Ads, social media, promotions'],
            ['5010', 'Automobile Expense', 'Expense', 'Auto', 'Vehicle fuel and maintenance'],
            ['5020', 'Bank Charges & Fees', 'Expense', 'Bank Charges', 'Bank service charges'],
            ['5030', 'Contract Labor', 'Expense', 'Contract Labor', 'Outside contractors'],
            ['5040', 'Insurance', 'Expense', 'Insurance', 'Business insurance premiums'],
            ['5050', 'Interest Paid', 'Expense', 'Interest Paid', 'Loan and card interest'],
            ['5060', 'Legal & Professional Fees', 'Expense', 'Legal & Professional Fees', 'Lawyer, auditor, consultant'],
            ['5070', 'Meals and Entertainment', 'Expense', 'Entertainment', 'Staff and client meals'],
            ['5080', 'Office Expenses & Supplies', 'Expense', 'Office/General Administrative Expenses', 'Stationery and office costs'],
            ['5090', 'Rent or Lease', 'Expense', 'Rent or Lease of Buildings', 'Premises rent'],
            ['5100', 'Repairs and Maintenance', 'Expense', 'Repair and Maintenance', 'Building and equipment repairs'],
            ['5110', 'Taxes & Licenses', 'Expense', 'Taxes Paid', 'Business taxes and licenses'],
            ['5120', 'Travel', 'Expense', 'Travel', 'Travel and lodging'],
            ['5130', 'Utilities', 'Expense', 'Utilities', 'Electricity, water, internet, gas'],
            ['5140', 'Payroll Expenses', 'Expense', 'Payroll Expenses', 'Staff salaries and wages'],
            ['5150', 'Steward & Casual Labor', 'Expense', 'Payroll Expenses', 'Steward and casual wages'],
            ['5160', 'Supplier Purchases', 'Expense', 'Supplies & Materials', 'Supplier and raw material purchases'],
            ['5170', 'Cleaning & Laundry', 'Expense', 'Other Miscellaneous Service Cost', 'Cleaning and laundry'],
            ['5180', 'Decorations & Floral', 'Expense', 'Other Miscellaneous Service Cost', 'Decor and floral costs'],
            ['5190', 'Equipment Rental', 'Expense', 'Equipment Rental', 'Rented equipment'],
            ['5200', 'Miscellaneous Expense', 'Expense', 'Other Miscellaneous Service Cost', 'Uncategorized expenses'],
        ];

        $existingCodes = DB::table('accounts_coa')->pluck('account_code')->filter()->map(fn ($c) => (string) $c)->all();
        $existingSet = array_flip($existingCodes);

        // Rename legacy generic expense if present
        if (isset($existingSet['5001'])) {
            DB::table('accounts_coa')->where('account_code', '5001')->update([
                'account_name' => 'Miscellaneous Expense (Legacy)',
                'detail_type' => 'Other Miscellaneous Service Cost',
                'updated_at' => $now,
            ]);
        }

        $rows = [];
        foreach ($seed as [$code, $name, $type, $detail, $desc]) {
            if (isset($existingSet[$code])) {
                DB::table('accounts_coa')->where('account_code', $code)->update([
                    'detail_type' => $detail,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid(),
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => $type,
                'detail_type' => $detail,
                'balance' => 0,
                'description' => $desc,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows) {
            DB::table('accounts_coa')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_entries');
        if (Schema::hasTable('accounts_coa')) {
            Schema::table('accounts_coa', function (Blueprint $table) {
                if (Schema::hasColumn('accounts_coa', 'is_active')) {
                    $table->dropColumn('is_active');
                }
                if (Schema::hasColumn('accounts_coa', 'detail_type')) {
                    $table->dropColumn('detail_type');
                }
            });
        }
    }
};
