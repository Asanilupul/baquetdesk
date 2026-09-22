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
        if (! Schema::hasTable('journal_vouchers')) {
            Schema::create('journal_vouchers', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('company_id')->nullable()->index();
                $table->string('voucher_date')->nullable()->index();
                $table->string('reference_no')->nullable()->index();
                $table->text('memo')->nullable();
                $table->string('source', 32)->default('manual');
                $table->string('source_id')->nullable()->index();
                $table->string('status', 16)->default('posted');
                $table->decimal('total_debit', 14, 2)->default(0);
                $table->decimal('total_credit', 14, 2)->default(0);
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('journal_entries') && ! Schema::hasColumn('journal_entries', 'voucher_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('voucher_id')->nullable()->index()->after('id');
            });
        }

        $this->backfillVouchers();
    }

    private function backfillVouchers(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_vouchers')) {
            return;
        }
        if (! Schema::hasColumn('journal_entries', 'voucher_id')) {
            return;
        }

        $now = now();
        $hasCompany = Schema::hasColumn('journal_entries', 'company_id');

        $orphans = DB::table('journal_entries')->whereNull('voucher_id')->orderBy('entry_date')->orderBy('id')->get();
        if ($orphans->isEmpty()) {
            return;
        }

        $groups = [];
        foreach ($orphans as $line) {
            $company = $hasCompany ? (string) ($line->company_id ?? '') : '';
            $ref = trim((string) ($line->reference_no ?? ''));
            $key = $ref !== ''
                ? $company.'|ref|'.$ref
                : $company.'|line|'.$line->id;
            $groups[$key][] = $line;
        }

        foreach ($groups as $key => $lines) {
            $first = $lines[0];
            $companyId = $hasCompany ? (string) ($first->company_id ?? '') : '';
            $ref = trim((string) ($first->reference_no ?? ''));
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            foreach ($lines as $line) {
                $totalDebit += (float) ($line->debit ?? 0);
                $totalCredit += (float) ($line->credit ?? 0);
            }

            $source = 'manual';
            $sourceId = null;
            if (str_starts_with($ref, 'PAY-')) {
                $source = 'payment';
                $sourceId = substr($ref, 4);
            } elseif (str_starts_with($ref, 'EXP-')) {
                $source = 'expense';
                $sourceId = substr($ref, 4);
            }

            $voucherId = (string) Str::uuid();
            $voucher = [
                'id' => $voucherId,
                'voucher_date' => $first->entry_date ?? null,
                'reference_no' => $ref !== '' ? $ref : ('LEGACY-'.$voucherId),
                'memo' => 'Backfilled from existing journal lines',
                'source' => $source,
                'source_id' => $sourceId,
                'status' => 'posted',
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'created_by' => 'migration',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('journal_vouchers', 'company_id') && $companyId !== '') {
                $voucher['company_id'] = $companyId;
            }
            DB::table('journal_vouchers')->insert($voucher);

            $ids = array_map(fn ($l) => $l->id, $lines);
            DB::table('journal_entries')->whereIn('id', $ids)->update([
                'voucher_id' => $voucherId,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_entries') && Schema::hasColumn('journal_entries', 'voucher_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropColumn('voucher_id');
            });
        }
        Schema::dropIfExists('journal_vouchers');
    }
};
