<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendSubscriptionRemindersCommand extends Command
{
    protected $signature = 'subscriptions:remind {--days=7 : Remind when expiry is within this many days}';

    protected $description = 'Email companies whose subscription expires within a week (payment reminder)';

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'subscription_expires_at')) {
            $this->warn('Companies subscription columns missing.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $now = now();
        $until = $now->copy()->addDays($days);

        $query = DB::table('companies')
            ->where('status', 'Active')
            ->where('subscription_status', 'Active')
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '>', $now)
            ->where('subscription_expires_at', '<=', $until)
            ->where(function ($q) {
                $q->whereNull('subscription_plan')->orWhere('subscription_plan', '!=', 'lifetime');
            });

        $companies = $query->get();
        $sent = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            $expiresAt = Carbon::parse($company->subscription_expires_at);
            $email = trim((string) ($company->email ?? ''));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $admin = DB::table('users')
                    ->where('company_id', $company->id)
                    ->where('role', 'Admin')
                    ->orderBy('created_at')
                    ->first();
                // No admin email column in users — skip if company email missing
                $this->warn("Skip {$company->name}: no valid company email");
                $skipped++;

                continue;
            }

            if (Schema::hasColumn('companies', 'subscription_reminder_sent_at') && $company->subscription_reminder_sent_at) {
                $sentAt = Carbon::parse($company->subscription_reminder_sent_at);
                // Already reminded for this expiry window (within last $days days)
                if ($sentAt->greaterThanOrEqualTo($now->copy()->subDays($days))) {
                    $skipped++;

                    continue;
                }
            }

            $daysLeft = max(0, (int) $now->diffInDays($expiresAt, false));
            $appName = config('app.name', 'BanquetDesk');
            $subject = "{$appName} subscription payment reminder — {$company->name}";
            $body = implode("\n", [
                "Hello {$company->name},",
                '',
                'This is a reminder that your BanquetDesk subscription is ending soon.',
                '',
                'Company: '.$company->name,
                'Plan: '.($company->subscription_plan ?: 'n/a'),
                'Expires on: '.$expiresAt->toDayDateTimeString(),
                'Days remaining: '.$daysLeft,
                '',
                'Please renew your subscription / complete payment before the end date to avoid service interruption.',
                '',
                'If you already paid, contact support so your Super Admin can extend the plan.',
                '',
                '— '.$appName,
            ]);

            try {
                Mail::raw($body, function ($message) use ($email, $subject, $company) {
                    $message->to($email, $company->name)->subject($subject);
                });

                if (Schema::hasColumn('companies', 'subscription_reminder_sent_at')) {
                    DB::table('companies')->where('id', $company->id)->update([
                        'subscription_reminder_sent_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->info("Reminder sent to {$email} ({$company->name})");
                $sent++;
            } catch (Throwable $e) {
                $this->error("Failed {$company->name}: ".$e->getMessage());
                $skipped++;
            }
        }

        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
