<?php

namespace Tests\Feature;

use App\Support\CompanyApiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LedgerJournalWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $token;

    private string $cashId;

    private string $revenueId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();

        if (Schema::hasTable('companies')) {
            $row = [
                'id' => $this->companyId,
                'name' => 'GL Guard Co',
                'status' => 'Active',
                'subscription_status' => 'Active',
                'subscription_plan' => 'lifetime',
                'subscription_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $cols = Schema::getColumnListing('companies');
            DB::table('companies')->insert(array_intersect_key($row, array_flip($cols)));
        }

        $userId = (string) Str::uuid();
        $user = [
            'id' => $userId,
            'username' => 'gladmin',
            'password' => Hash::make('secret123'),
            'role' => 'Admin',
            'company_id' => $this->companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $cols = Schema::getColumnListing('users');
        DB::table('users')->insert(array_intersect_key($user, array_flip($cols)));

        $this->token = CompanyApiSession::issue([
            'user_id' => $userId,
            'company_id' => $this->companyId,
            'role' => 'Admin',
            'username' => 'gladmin',
        ]);

        $this->cashId = $this->seedAccount('1000', 'Cash on Hand', 'Asset');
        $this->revenueId = $this->seedAccount('4000', 'Service Income', 'Revenue');
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

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse
     */
    private function dbQuery(array $payload)
    {
        return $this->postJson('/api/db/query', $payload, [
            'X-Api-Token' => $this->token,
            'X-Company-Id' => $this->companyId,
        ]);
    }

    public function test_direct_journal_entry_insert_is_rejected(): void
    {
        $response = $this->dbQuery([
            'action' => 'insert',
            'table' => 'journal_entries',
            'payload' => [
                'id' => (string) Str::uuid(),
                'entry_date' => '2026-09-22',
                'account_id' => $this->cashId,
                'debit' => 100,
                'credit' => 0,
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('post_journal_voucher', (string) $response->json('error.message'));
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_post_journal_voucher_action_updates_balances(): void
    {
        $response = $this->dbQuery([
            'action' => 'post_journal_voucher',
            'payload' => [
                'header' => [
                    'voucher_date' => '2026-09-22',
                    'reference_no' => 'JV-API-1',
                    'memo' => 'API post',
                    'source' => 'manual',
                ],
                'lines' => [
                    ['account_id' => $this->cashId, 'debit' => 250, 'credit' => 0],
                    ['account_id' => $this->revenueId, 'debit' => 0, 'credit' => 250],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('error', null);
        $this->assertSame('posted', $response->json('data.voucher.status'));

        $cash = DB::table('accounts_coa')->where('id', $this->cashId)->first();
        $this->assertEquals(250.0, (float) $cash->balance);
    }
}
