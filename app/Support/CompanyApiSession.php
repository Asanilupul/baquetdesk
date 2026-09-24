<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CompanyApiSession
{
    private const TTL_SECONDS = 60 * 60 * 12;

    /**
     * @param  array{user_id: string, company_id: string, role: string, username: string}  $claims
     */
    public static function issue(array $claims): string
    {
        $token = Str::random(64);
        $userId = (string) ($claims['user_id'] ?? '');
        Cache::put(self::cacheKey($token), [
            'user_id' => $userId,
            'company_id' => (string) ($claims['company_id'] ?? ''),
            'role' => (string) ($claims['role'] ?? ''),
            'username' => (string) ($claims['username'] ?? ''),
        ], self::TTL_SECONDS);

        if ($userId !== '') {
            $tokens = array_values(array_filter(
                (array) Cache::get(self::userIndexKey($userId), []),
                fn ($existing) => is_string($existing) && Cache::has(self::cacheKey($existing))
            ));
            $tokens[] = $token;
            Cache::put(self::userIndexKey($userId), $tokens, self::TTL_SECONDS);
        }

        return $token;
    }

    /**
     * @return array{user_id: string, company_id: string, role: string, username: string}|null
     */
    public static function validate(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $cached = Cache::get(self::cacheKey($token));
        if (! is_array($cached)) {
            return null;
        }

        return [
            'user_id' => (string) ($cached['user_id'] ?? ''),
            'company_id' => (string) ($cached['company_id'] ?? ''),
            'role' => (string) ($cached['role'] ?? ''),
            'username' => (string) ($cached['username'] ?? ''),
        ];
    }

    /**
     * @return array{user_id: string, company_id: string, role: string, username: string}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        return self::validate(self::tokenFromRequest($request));
    }

    public static function tokenFromRequest(Request $request): string
    {
        $token = trim((string) $request->header('X-Api-Token', ''));
        if ($token === '') {
            $token = trim((string) $request->bearerToken());
        }

        return $token;
    }

    public static function revoke(string $token): void
    {
        $token = trim($token);
        if ($token !== '') {
            Cache::forget(self::cacheKey($token));
        }
    }

    /**
     * Revoke every token issued to a user (or vendor), optionally keeping the caller's own token.
     */
    public static function revokeUser(string $userId, ?string $exceptToken = null): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            return;
        }

        $kept = [];
        foreach ((array) Cache::get(self::userIndexKey($userId), []) as $token) {
            if (! is_string($token)) {
                continue;
            }
            if ($exceptToken !== null && hash_equals($token, $exceptToken)) {
                $kept[] = $token;

                continue;
            }
            Cache::forget(self::cacheKey($token));
        }

        if ($kept === []) {
            Cache::forget(self::userIndexKey($userId));
        } else {
            Cache::put(self::userIndexKey($userId), $kept, self::TTL_SECONDS);
        }
    }

    private static function cacheKey(string $token): string
    {
        return 'company_api_token:'.$token;
    }

    private static function userIndexKey(string $userId): string
    {
        return 'company_api_user_tokens:'.$userId;
    }
}
