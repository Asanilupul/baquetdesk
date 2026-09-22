<?php

namespace App\Console\Commands;

use App\Support\LedgerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:rebuild-balances {--company= : Limit to a company id}')]
#[Description('Recompute accounts_coa.balance from posted journal lines')]
class LedgerRebuildBalancesCommand extends Command
{
    public function handle(): int
    {
        $companyId = (string) ($this->option('company') ?? '');
        $count = LedgerService::rebuildBalances($companyId);
        $this->info("Rebuilt balances for {$count} account(s)".($companyId !== '' ? " (company {$companyId})" : '').'.');

        return self::SUCCESS;
    }
}
