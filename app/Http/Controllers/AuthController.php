<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Support\CompanyApiSession;
use App\Support\CompanySubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid credentials. Check your username and password.'],
            ], 401);
        }

        try {
            // 1) Staff / company users
            $user = DB::table('users')->where('username', $username)->first();
            if ($user && $this->passwordMatches($user, $password, 'users')) {
                if (($user->role ?? '') === 'SuperAdmin') {
                    return response()->json([
                        'data' => null,
                        'error' => ['message' => 'Use /su-admin for Super Admin login'],
                    ], 403);
                }

                $companyId = trim((string) ($user->company_id ?? ''));
                if ($companyId !== '' && Schema::hasTable('companies')) {
                    $company = DB::table('companies')->where('id', $companyId)->first();
                    if (! CompanySubscription::isUsable($company)) {
                        return response()->json([
                            'data' => null,
                            'error' => ['message' => CompanySubscription::denyMessage($company)],
                        ], 403);
                    }
                }

                $claims = [
                    'user_id' => (string) $user->id,
                    'company_id' => $companyId,
                    'role' => (string) ($user->role ?? ''),
                    'username' => (string) $user->username,
                ];
                $payload = $this->publicUser($user);
                $payload['api_token'] = CompanyApiSession::issue($claims);
                AuditLogger::record($claims, $companyId, 'login', ipAddress: $request->ip());

                return response()->json([
                    'data' => $payload,
                    'error' => null,
                ]);
            }

            // 2) Vendor portal users
            if (Schema::hasTable('vendors')) {
                $vendor = DB::table('vendors')->where('username', $username)->first();
                if ($vendor && $this->passwordMatches($vendor, $password, 'vendors')) {
                    $status = strtolower(trim((string) ($vendor->status ?? '')));
                    if ($status !== '' && $status !== 'active') {
                        return response()->json([
                            'data' => null,
                            'error' => ['message' => 'This vendor account is not active. Contact the banquet hall.'],
                        ], 403);
                    }

                    $payload = $this->publicUser($vendor);
                    $payload['role'] = 'Vendor';
                    $payload['api_token'] = CompanyApiSession::issue([
                        'user_id' => (string) $vendor->id,
                        'company_id' => trim((string) ($vendor->company_id ?? '')),
                        'role' => 'Vendor',
                        'username' => (string) ($vendor->username ?? ''),
                    ]);

                    return response()->json([
                        'data' => $payload,
                        'error' => null,
                    ]);
                }
            }

            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid credentials. Check your username and password.'],
            ], 401);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Authentication temporarily unavailable'],
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $token = CompanyApiSession::tokenFromRequest($request);
        $session = CompanyApiSession::validate($token);
        if ($session !== null) {
            CompanyApiSession::revoke($token);
            AuditLogger::record($session, $session['company_id'], 'logout', ipAddress: $request->ip());
        }

        return response()->json(['data' => ['logged_out' => true], 'error' => null]);
    }

    private function passwordMatches(object $row, string $plain, string $table): bool
    {
        $stored = (string) ($row->password ?? '');
        if ($stored === '') {
            return false;
        }

        if (Hash::isHashed($stored)) {
            return Hash::check($plain, $stored);
        }

        // Legacy plaintext upgrade path (one-time)
        if (hash_equals($stored, $plain)) {
            if (Schema::hasColumn($table, 'password')) {
                DB::table($table)->where('id', $row->id)->update([
                    'password' => Hash::make($plain),
                    'updated_at' => now(),
                ]);
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUser(object $row): array
    {
        $arr = (array) $row;
        unset($arr['password']);

        // JSON columns come back as strings from the DB driver; the SPA expects
        // allowed_modules to be null (full access) or a real array of module ids.
        if (array_key_exists('allowed_modules', $arr)) {
            $arr['allowed_modules'] = $this->decodeAllowedModules($arr['allowed_modules']);
        }

        return $arr;
    }

    private function decodeAllowedModules(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if ($decoded === null) {
                    return null;
                }
                if (is_array($decoded)) {
                    return array_values(array_map('strval', $decoded));
                }
            }
        }

        return null;
    }
}
