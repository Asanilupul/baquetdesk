<?php

namespace App\Http\Controllers;

use App\Support\CompanySubscription;
use App\Support\VendorInvites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SuperAdminController extends Controller
{
    private const TOKEN_TTL_SECONDS = 60 * 60 * 12;

    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Username and password are required'],
            ], 422);
        }

        $query = DB::table('users')
            ->where('username', $username)
            ->where('role', 'SuperAdmin');

        if (Schema::hasColumn('users', 'company_id')) {
            $query->where(function ($q) {
                $q->whereNull('company_id')->orWhere('company_id', '');
            });
        }

        $user = $query->first();
        if (! $user || ! $this->passwordMatches($user, $password)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid Super Admin credentials'],
            ], 401);
        }

        $token = Str::random(64);
        Cache::put($this->tokenCacheKey($token), [
            'user_id' => $user->id,
            'username' => $user->username,
            'role' => 'SuperAdmin',
        ], self::TOKEN_TTL_SECONDS);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'role' => 'SuperAdmin',
                ],
            ],
            'error' => null,
        ]);
    }

    public function companies(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSu($request)) {
            return $deny;
        }

        if (! Schema::hasTable('companies')) {
            return response()->json(['data' => [], 'error' => null]);
        }

        $rows = DB::table('companies')->orderBy('created_at', 'desc')->get();
        $data = [];

        foreach ($rows as $company) {
            $company = CompanySubscription::refreshStatus($company);
            $admin = DB::table('users')
                ->where('company_id', $company->id)
                ->where('role', 'Admin')
                ->orderBy('created_at')
                ->first();

            $data[] = [
                'id' => $company->id,
                'name' => $company->name,
                'phone' => $company->phone ?? '',
                'email' => $company->email ?? '',
                'status' => $company->status ?? 'Active',
                'subscription_plan' => $company->subscription_plan ?? null,
                'subscription_expires_at' => $company->subscription_expires_at ?? null,
                'subscription_status' => $company->subscription_status ?? 'Pending',
                'usable' => CompanySubscription::isUsable($company),
                'admin_username' => $admin->username ?? null,
                'created_at' => $company->created_at ?? null,
            ];
        }

        return response()->json(['data' => $data, 'error' => null]);
    }

    public function updateSubscription(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessSu($request)) {
            return $deny;
        }

        $plan = trim((string) $request->input('plan', ''));

        try {
            $result = CompanySubscription::applyPlan($id, $plan);

            return response()->json(['data' => $result, 'error' => null]);
        } catch (Throwable $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], $code);
        }
    }

    public function setCompanyStatus(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessSu($request)) {
            return $deny;
        }

        $status = trim((string) $request->input('status', ''));

        try {
            $result = CompanySubscription::setAccountStatus($id, $status);

            return response()->json(['data' => $result, 'error' => null]);
        } catch (Throwable $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], $code);
        }
    }

    /**
     * Every registered vendor grouped by company, with recent registration links.
     */
    public function vendors(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSu($request)) {
            return $deny;
        }

        $decode = function (object $vendor): array {
            $row = (array) $vendor;
            unset($row['password']);
            foreach (['pictures', 'packages'] as $column) {
                if (isset($row[$column]) && is_string($row[$column])) {
                    $row[$column] = json_decode($row[$column], true) ?: [];
                }
            }

            return $row;
        };

        $vendorsByCompany = DB::table('vendors')->orderBy('vendor_name')->get()->groupBy(fn ($v) => (string) ($v->company_id ?? ''));
        $invitesByCompany = Schema::hasTable('vendor_invites')
            ? DB::table('vendor_invites')->orderByDesc('created_at')->get()->groupBy('company_id')
            : collect();

        $groups = DB::table('companies')->orderBy('name')->get()->map(fn (object $company) => [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'vendors' => ($vendorsByCompany[$company->id] ?? collect())->map($decode)->values()->all(),
            'invites' => ($invitesByCompany[$company->id] ?? collect())->take(20)->map(fn ($i) => VendorInvites::present($i))->values()->all(),
        ])->all();

        $unassigned = ($vendorsByCompany[''] ?? collect())->map($decode)->values()->all();
        if ($unassigned !== []) {
            $groups[] = ['company_id' => null, 'company_name' => 'Not linked to a company', 'vendors' => $unassigned, 'invites' => []];
        }

        return response()->json(['data' => $groups, 'error' => null]);
    }

    public function createVendorInvites(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSu($request)) {
            return $deny;
        }

        $companyIds = array_values(array_unique(array_filter(array_map('strval', (array) $request->input('company_ids', [])))));
        if ($companyIds === []) {
            return response()->json(['data' => null, 'error' => ['message' => 'Select at least one company.']], 422);
        }

        $companies = DB::table('companies')->whereIn('id', $companyIds)->orderBy('name')->get();
        $links = $companies->map(function (object $company) use ($request) {
            $created = VendorInvites::create($company->id, null, 'Super Admin', (string) $request->input('note', ''));

            return [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'link' => $created['link'],
                'expires_at' => $created['invite']['expires_at'],
            ];
        })->values()->all();

        return response()->json(['data' => $links, 'error' => null]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $session = null;
        if ($deny = $this->denyUnlessSu($request, $session)) {
            return $deny;
        }

        $current = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');

        if ($current === '' || $newPassword === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Current and new password are required'],
            ], 422);
        }

        if (strlen($newPassword) < 10) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'New password must be at least 10 characters'],
            ], 422);
        }

        if ($newPassword !== $confirm) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'New password confirmation does not match'],
            ], 422);
        }

        $userId = (string) ($session['user_id'] ?? '');
        $user = DB::table('users')->where('id', $userId)->where('role', 'SuperAdmin')->first();
        if (! $user || ! $this->passwordMatches($user, $current)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Current password is incorrect'],
            ], 403);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($newPassword),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => ['ok' => true, 'username' => $user->username],
            'error' => null,
        ]);
    }

    private function passwordMatches(object $row, string $plain): bool
    {
        $stored = (string) ($row->password ?? '');
        if ($stored === '') {
            return false;
        }

        if (Hash::isHashed($stored)) {
            return Hash::check($plain, $stored);
        }

        if (hash_equals($stored, $plain)) {
            DB::table('users')->where('id', $row->id)->update([
                'password' => Hash::make($plain),
                'updated_at' => now(),
            ]);

            return true;
        }

        return false;
    }

    private function denyUnlessSu(Request $request, ?array &$session = null): ?JsonResponse
    {
        $token = trim((string) $request->header('X-SU-Token', ''));
        if ($token === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Super Admin token required'],
            ], 401);
        }

        $cached = Cache::get($this->tokenCacheKey($token));
        if (! is_array($cached) || ($cached['role'] ?? '') !== 'SuperAdmin') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid or expired Super Admin session'],
            ], 401);
        }

        $session = $cached;

        return null;
    }

    private function tokenCacheKey(string $token): string
    {
        return 'su_admin_token:'.$token;
    }
}
