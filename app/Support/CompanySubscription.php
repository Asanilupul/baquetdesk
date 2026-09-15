<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanySubscription
{
    public const PLANS = ['1_month', '12_months', 'lifetime'];

    public static function refreshStatus(?object $company): ?object
    {
        if (! $company || ! Schema::hasColumn('companies', 'subscription_status')) {
            return $company;
        }

        $status = (string) ($company->subscription_status ?? 'Pending');
        $plan = (string) ($company->subscription_plan ?? '');
        $expiresAt = $company->subscription_expires_at ?? null;

        if ($status === 'Active' && $plan !== 'lifetime' && $expiresAt) {
            if (Carbon::parse($expiresAt)->isPast()) {
                DB::table('companies')->where('id', $company->id)->update([
                    'subscription_status' => 'Expired',
                    'updated_at' => now(),
                ]);
                $company->subscription_status = 'Expired';
            }
        }

        return $company;
    }

    public static function isUsable(?object $company): bool
    {
        if (! $company) {
            return false;
        }

        // Legacy rows without subscription columns
        if (! Schema::hasColumn('companies', 'subscription_status')) {
            return true;
        }

        $company = self::refreshStatus($company);
        $status = (string) ($company->subscription_status ?? '');
        $plan = (string) ($company->subscription_plan ?? '');

        if ($status !== 'Active') {
            return false;
        }

        if ($plan === 'lifetime') {
            return true;
        }

        $expiresAt = $company->subscription_expires_at ?? null;
        if (! $expiresAt) {
            return false;
        }

        return Carbon::parse($expiresAt)->isFuture();
    }

    public static function applyPlan(string $companyId, string $plan): array
    {
        if (! in_array($plan, self::PLANS, true)) {
            throw new \InvalidArgumentException('Invalid plan. Use 1_month, 12_months, or lifetime.');
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        if (! $company) {
            throw new \RuntimeException('Company not found');
        }

        $now = now();
        $expiresAt = null;

        if ($plan === '1_month' || $plan === '12_months') {
            $base = $now;
            $currentExpiry = $company->subscription_expires_at ?? null;
            if ($currentExpiry && Carbon::parse($currentExpiry)->isFuture()) {
                $base = Carbon::parse($currentExpiry);
            }
            $expiresAt = $plan === '1_month'
                ? $base->copy()->addMonth()
                : $base->copy()->addMonths(12);
        }

        $payload = [
            'subscription_plan' => $plan,
            'subscription_expires_at' => $expiresAt,
            'subscription_status' => 'Active',
            'updated_at' => $now,
        ];

        DB::table('companies')->where('id', $companyId)->update($payload);

        return array_merge(['id' => $companyId], $payload, [
            'subscription_expires_at' => $expiresAt?->toDateTimeString(),
        ]);
    }

    public static function denyMessage(?object $company): string
    {
        $status = (string) ($company->subscription_status ?? 'Pending');
        if ($status === 'Pending') {
            return 'Subscription pending. A Super Admin must activate your company before login.';
        }
        if ($status === 'Expired') {
            return 'Subscription expired. Contact support / Super Admin to renew.';
        }

        return 'Subscription expired or inactive. Contact support.';
    }
}
