<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('users')->upsert([
            [
                'id' => (string) Str::uuid(),
                'username' => 'admin',
                'password' => Hash::make('admin123'),
                'role' => 'Admin',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'manager',
                'password' => Hash::make('manager123'),
                'role' => 'Manager',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'accountant',
                'password' => Hash::make('account123'),
                'role' => 'Accountant',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['username'], ['password', 'role', 'updated_at']);

        // Fix manager password to match original SPA default
        DB::table('users')->where('username', 'manager')->update([
            'password' => Hash::make('snap123'),
            'updated_at' => $now,
        ]);

        // Developer Super Admin (no company scope)
        $suPayload = [
            'username' => 'suadmin',
            'password' => Hash::make('SuAdmin@2026'),
            'role' => 'SuperAdmin',
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('users', 'company_id')) {
            $suPayload['company_id'] = null;
        }
        $existingSu = DB::table('users')->where('username', 'suadmin')->first();
        if ($existingSu) {
            DB::table('users')->where('username', 'suadmin')->update($suPayload);
        } else {
            DB::table('users')->insert(array_merge($suPayload, [
                'id' => (string) Str::uuid(),
                'created_at' => $now,
            ]));
        }
        $employees = [
            'Madusanka', 'Nissanka', 'Eranda', 'Imesha', 'Bhagya',
            'Asanga', 'Jalani', 'Hettiarachchi', 'Senarathne',
            'Aloka', 'Irosh', 'Rathnayake', 'Vishan', 'karl', 'Chamuditha',
        ];

        if (DB::table('employees')->count() === 0) {
            $rows = [];
            foreach ($employees as $i => $name) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'name' => $name,
                    'empNo' => 'EMP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'joinDate' => '2024-01-01',
                    'status' => 'Active',
                    'section' => 'General',
                    'basicSalary' => 40000,
                    'responsibilityAllowance' => 0,
                    'attendanceAllowance' => 0,
                    'otherAllowance' => 0,
                    'epfEmployee' => 8,
                    'welfare' => 0,
                    'mealDeduction' => 0,
                    'deposit' => 0,
                    'dayRate' => 2000,
                    'leaves' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('employees')->insert($rows);
        }

        $settings = [
            'company_name' => '',
            'company_subtitle' => '',
            'company_phone' => '',
            'company_email' => '',
            'company_address' => '',
            'company_logo' => '',
            'function_sheets_header' => 'BANQUETDESK RESORT OPERATIONS',
            'function_sheets_subtitle' => 'EVENT OPERATIONS SPECIFICATION SHEET',
            'function_sheets_footer' => 'Powered by BanquetDesk',
            'function_sheets_watermark' => 'BanquetDesk',
            'quotation_header' => 'Quotation',
            'quotation_footer' => 'Thank you for your business',
            'quotation_watermark' => 'QUOTATION',
            'invoice_header' => 'Invoice',
            'invoice_footer' => 'Thank you for your business',
            'invoice_watermark' => 'INVOICE',
            'advance_agreement_title' => 'Service Agreement & Terms and Conditions',
            'advance_agreement_terms' => "1. This Advance Payment Invoice confirms receipt of the advance payment toward the booked function/event.\n2. The advance amount forms part of the total function charges and will be deducted from the final invoice.\n3. The booking is confirmed only upon receipt of this advance, as agreed with the company.\n4. Cancellations, postponements, and menu or venue changes are subject to the company cancellation and revision policy.\n5. Any remaining balance must be settled as per the agreed payment schedule before or on the function date, unless otherwise agreed in writing.\n6. The company may adjust charges if guest count (pax), menu, hall, or extras change after this agreement.\n7. The customer is responsible for providing accurate event details and for damages caused by their guests to company property.\n8. By signing below, the customer confirms they have read, understood, and agree to these Terms and Conditions.",
            'payroll_header' => 'Payroll Department',
            'payroll_footer' => 'This is a computer generated document and does not require a signature.',
            'payroll_watermark' => 'PAYSLIP',
            'kitchen_header' => 'KITCHEN PRODUCTION SHEET',
            'kitchen_footer' => 'BanquetDesk Kitchen',
            'kitchen_watermark' => 'BanquetDesk',
            'general_header' => 'BanquetDesk',
            'general_footer' => 'Powered by BanquetDesk',
            'general_watermark' => 'BanquetDesk',
        ];

        foreach ($settings as $key => $value) {
            $existing = DB::table('system_settings')->where('key', $key)->first();
            if ($existing) {
                // Never wipe registered company branding on re-seed
                $isCompanyKey = str_starts_with($key, 'company_');
                if ($isCompanyKey && filled($existing->value)) {
                    continue;
                }
                // Only fill empty non-company defaults; leave customized values alone
                if (filled($existing->value)) {
                    continue;
                }
                DB::table('system_settings')->where('key', $key)->update([
                    'value' => $value,
                    'updated_at' => $now,
                ]);
                continue;
            }

            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $vendorCategories = [
            'Photographer',
            'Astaka Jayamangala gatha',
            'Beauty saloon',
            'Wedding Decorations',
            'Party Decorations',
            'Wedding car',
            'Dancing group / Ashtaka / Jayamangala',
            'Cake structure / Table deco',
            'Cake boxes / Cake pieces',
            'Band / DJ / Sound / Light',
            'Wedding invitation card',
            'Bridal / Groom wear',
            'Gift item',
        ];

        foreach ($vendorCategories as $name) {
            DB::table('vendor_categories')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (DB::table('accounts_coa')->count() === 0) {
            DB::table('accounts_coa')->insert([
                [
                    'id' => (string) Str::uuid(),
                    'account_code' => '1001',
                    'account_name' => 'Cash Account',
                    'account_type' => 'Asset',
                    'balance' => 0,
                    'description' => 'Cash in Hand',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'account_code' => '1002',
                    'account_name' => 'Bank Account',
                    'account_type' => 'Asset',
                    'balance' => 0,
                    'description' => 'Bank Balance',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'account_code' => '4001',
                    'account_name' => 'Revenue',
                    'account_type' => 'Revenue',
                    'balance' => 0,
                    'description' => 'Catering Revenue',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'account_code' => '5001',
                    'account_name' => 'Expenses',
                    'account_type' => 'Expense',
                    'balance' => 0,
                    'description' => 'Operational Expenses',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        $defaultHalls = [
            'Grand ball room',
            'Orchid',
            'Mini orchid',
            'Tulip',
            'Mini tulip',
            'Roof top',
            'Cafe room',
        ];

        if (DB::table('halls')->count() === 0) {
            $rows = [];
            foreach ($defaultHalls as $name) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'name' => $name,
                    'capacity' => null,
                    'notes' => null,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('halls')->insert($rows);
        }

        if (Schema::hasTable('menu_extras') && DB::table('menu_extras')->count() === 0) {
            $defaultBites = [
                'Devilled Chicken',
                'Hot Butter Cuttlefish',
                'French Fries',
                'Devilled Pork',
                'Chicken Sausages',
                'Fried Cashews',
                'Fish Cutlets',
                'Cheese Balls',
            ];
            $defaultDrinks = [
                'Coca Cola',
                'Sprite',
                'Fanta',
                'Ginger Beer',
                'Soda',
                'Fruit Juice',
                'Mineral Water',
                'Iced Tea',
            ];
            $extraRows = [];
            foreach ($defaultBites as $name) {
                $extraRows[] = [
                    'id' => (string) Str::uuid(),
                    'type' => 'bite',
                    'name' => $name,
                    'price' => 0,
                    'unit' => 'portion',
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach ($defaultDrinks as $name) {
                $extraRows[] = [
                    'id' => (string) Str::uuid(),
                    'type' => 'softdrink',
                    'name' => $name,
                    'price' => 0,
                    'unit' => '1 L',
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('menu_extras')->insert($extraRows);
        }
    }
}
