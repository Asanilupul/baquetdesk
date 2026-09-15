<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CompanyController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $companyName = trim((string) $request->input('company_name', ''));
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $phone = trim((string) $request->input('phone', ''));
        $email = trim((string) $request->input('email', ''));
        $address = trim((string) $request->input('address', ''));
        $subtitle = trim((string) $request->input('subtitle', ''));

        if ($companyName === '' || $username === '' || $password === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Company name, admin username and password are required'],
            ], 422);
        }

        if (strlen($password) < 4) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Password must be at least 4 characters'],
            ], 422);
        }

        if (DB::table('users')->where('username', $username)->exists()) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Username already taken. Choose another admin username.'],
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($companyName, $username, $password, $phone, $email, $address, $subtitle) {
                $now = now();
                $companyId = (string) Str::uuid();
                $userId = (string) Str::uuid();

                DB::table('companies')->insert([
                    'id' => $companyId,
                    'name' => $companyName,
                    'subtitle' => $subtitle,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'logo' => '',
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->insert([
                    'id' => $userId,
                    'username' => $username,
                    'password' => $password,
                    'role' => 'Admin',
                    'company_id' => $companyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->seedCompanyDefaults($companyId, $now);

                return [
                    'company' => [
                        'id' => $companyId,
                        'name' => $companyName,
                        'subtitle' => $subtitle,
                        'phone' => $phone,
                        'email' => $email,
                        'address' => $address,
                        'status' => 'Active',
                    ],
                    'user' => [
                        'id' => $userId,
                        'username' => $username,
                        'role' => 'Admin',
                        'company_id' => $companyId,
                    ],
                ];
            });

            return response()->json(['data' => $result, 'error' => null]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $company = DB::table('companies')->where('id', $id)->first();
        if (! $company) {
            return response()->json(['data' => null, 'error' => ['message' => 'Company not found']], 404);
        }

        return response()->json(['data' => (array) $company, 'error' => null]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $company = DB::table('companies')->where('id', $id)->first();
        if (! $company) {
            return response()->json(['data' => null, 'error' => ['message' => 'Company not found']], 404);
        }

        $payload = [
            'name' => trim((string) $request->input('name', $company->name)),
            'subtitle' => trim((string) $request->input('subtitle', $company->subtitle ?? '')),
            'phone' => trim((string) $request->input('phone', $company->phone ?? '')),
            'email' => trim((string) $request->input('email', $company->email ?? '')),
            'address' => trim((string) $request->input('address', $company->address ?? '')),
            'logo' => (string) $request->input('logo', $company->logo ?? ''),
            'updated_at' => now(),
        ];

        DB::table('companies')->where('id', $id)->update($payload);

        return response()->json(['data' => array_merge(['id' => $id], $payload), 'error' => null]);
    }

    private function seedCompanyDefaults(string $companyId, $now): void
    {
        $halls = ['Grand ball room', 'Orchid', 'Mini orchid', 'Tulip', 'Mini tulip', 'Roof top', 'Cafe room'];
        if (Schema::hasTable('halls')) {
            foreach ($halls as $name) {
                DB::table('halls')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => $name,
                    'capacity' => null,
                    'notes' => null,
                    'status' => 'Active',
                    'company_id' => $companyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $coa = [
            ['1000', 'Cash on Hand', 'Asset', 'Cash on hand'],
            ['1050', 'Bank Account', 'Asset', 'Bank'],
            ['2000', 'Accounts Payable (A/P)', 'Liability', 'Accounts Payable (A/P)'],
            ['3000', 'Opening Balance Equity', 'Equity', 'Opening Balance Equity'],
            ['4000', 'Catering Sales', 'Revenue', 'Service/Fee Income'],
            ['5000', 'Advertising & Marketing', 'Expense', 'Advertising/Promotional'],
            ['5080', 'Office Expenses & Supplies', 'Expense', 'Office/General Administrative Expenses'],
            ['5130', 'Utilities', 'Expense', 'Utilities'],
            ['5140', 'Payroll Expenses', 'Expense', 'Payroll Expenses'],
            ['5160', 'Supplier Purchases', 'Expense', 'Supplies & Materials'],
            ['5200', 'Miscellaneous Expense', 'Expense', 'Other Miscellaneous Service Cost'],
        ];
        if (Schema::hasTable('accounts_coa')) {
            foreach ($coa as [$code, $name, $type, $detail]) {
                $row = [
                    'id' => (string) Str::uuid(),
                    'account_code' => $code,
                    'account_name' => $name,
                    'account_type' => $type,
                    'balance' => 0,
                    'description' => $detail,
                    'company_id' => $companyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (Schema::hasColumn('accounts_coa', 'detail_type')) {
                    $row['detail_type'] = $detail;
                }
                if (Schema::hasColumn('accounts_coa', 'is_active')) {
                    $row['is_active'] = true;
                }
                DB::table('accounts_coa')->insert($row);
            }
        }
    }
}
