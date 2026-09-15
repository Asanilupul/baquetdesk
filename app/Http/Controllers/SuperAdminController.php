<?php

namespace App\Http\Controllers;

use App\Support\CompanySubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            ->where('password', $password)
            ->where('role', 'SuperAdmin');

        if (Schema::hasColumn('users', 'company_id')) {
            $query->where(function ($q) {
                $q->whereNull('company_id')->orWhere('company_id', '');
            });
        }

        $user = $query->first();
        if (! $user) {
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
                'status' => $company->status ?? '',
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

    private function denyUnlessSu(Request $request): ?JsonResponse
    {
        $token = trim((string) $request->header('X-SU-Token', ''));
        if ($token === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Super Admin token required'],
            ], 401);
        }

        $session = Cache::get($this->tokenCacheKey($token));
        if (! is_array($session) || ($session['role'] ?? '') !== 'SuperAdmin') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid or expired Super Admin session'],
            ], 401);
        }

        return null;
    }

    private function tokenCacheKey(string $token): string
    {
        return 'su_admin_token:'.$token;
    }
}
