<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts_coa')) {
            return;
        }

        $now = now();
        $companies = Schema::hasTable('companies')
            ? DB::table('companies')->pluck('id')->all()
            : [null];

        foreach ($companies as $companyId) {
            $q = DB::table('accounts_coa')->where('account_code', '2300');
            if ($companyId !== null && Schema::hasColumn('accounts_coa', 'company_id')) {
                $q->where('company_id', $companyId);
            }
            if ($q->exists()) {
                continue;
            }

            $row = [
                'id' => (string) Str::uuid(),
                'account_code' => '2300',
                'account_name' => 'Customer Deposits',
                'account_type' => 'Liability',
                'balance' => 0,
                'description' => 'Advances from customers',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($companyId !== null && Schema::hasColumn('accounts_coa', 'company_id')) {
                $row['company_id'] = $companyId;
            }
            if (Schema::hasColumn('accounts_coa', 'detail_type')) {
                $row['detail_type'] = 'Other Current Liabilities';
            }
            if (Schema::hasColumn('accounts_coa', 'is_active')) {
                $row['is_active'] = true;
            }
            DB::table('accounts_coa')->insert($row);
        }
    }

    public function down(): void
    {
        // Keep deposit accounts — may hold balances.
    }
};
