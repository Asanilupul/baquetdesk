<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Single-use, company-bound vendor registration links.
 */
class VendorInvites
{
    public const TTL_DAYS = 14;

    public const INVALID_LINK_MESSAGE = 'This vendor registration link is invalid, expired or has already been used. Ask the banquet hall for a new link.';

    /** @var list<string> */
    private const DEFAULT_CATEGORIES = ['Photographer', 'Videographer', 'Decorator', 'Florist', 'DJ / Music', 'Cake', 'Other'];

    /**
     * @return array{invite: array<string, mixed>, token: string, link: string}
     */
    public static function create(string $companyId, ?string $createdBy, string $createdByName, ?string $note): array
    {
        $token = Str::random(48);
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'token_hash' => self::hashToken($token),
            'note' => ($note = trim((string) $note)) === '' ? null : Str::limit($note, 190, ''),
            'created_by' => $createdBy,
            'created_by_name' => $createdByName,
            'expires_at' => $now->copy()->addDays(self::TTL_DAYS),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('vendor_invites')->insert($row);

        return [
            'invite' => self::present((object) $row),
            'token' => $token,
            'link' => self::linkFor($token),
        ];
    }

    public static function linkFor(string $token): string
    {
        return url('/').'?vendor_invite='.$token;
    }

    public static function findUsable(string $token): ?object
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $invite = DB::table('vendor_invites')->where('token_hash', self::hashToken($token))->first();

        return $invite && self::status($invite) === 'pending' ? $invite : null;
    }

    public static function status(object $invite): string
    {
        if (! empty($invite->used_at)) {
            return 'used';
        }
        if (! empty($invite->revoked_at)) {
            return 'revoked';
        }
        if (! empty($invite->expires_at) && now()->greaterThan($invite->expires_at)) {
            return 'expired';
        }

        return 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(object $invite): array
    {
        $vendorName = null;
        if (! empty($invite->vendor_id)) {
            $vendorName = DB::table('vendors')->where('id', $invite->vendor_id)->value('vendor_name');
        }

        return [
            'id' => $invite->id,
            'company_id' => $invite->company_id,
            'note' => $invite->note,
            'created_by_name' => $invite->created_by_name,
            'created_at' => (string) $invite->created_at,
            'expires_at' => $invite->expires_at ? (string) $invite->expires_at : null,
            'used_at' => $invite->used_at ?? null,
            'vendor_id' => $invite->vendor_id ?? null,
            'vendor_name' => $vendorName,
            'status' => self::status($invite),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForCompany(string $companyId, int $limit = 100): array
    {
        return DB::table('vendor_invites')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $invite) => self::present($invite))
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function categoriesFor(string $companyId): array
    {
        $names = DB::table('vendor_categories')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $names === [] ? self::DEFAULT_CATEGORIES : $names;
    }

    /**
     * Consume the invite and create the vendor inside the invite's company.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function register(string $token, array $data): array
    {
        $vendorName = trim((string) ($data['vendor_name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $category = trim((string) ($data['category'] ?? ''));

        if ($vendorName === '' || $username === '' || $category === '' || strlen($password) < 8) {
            throw new InvalidArgumentException('Vendor name, at least one category, username and a password of at least 8 characters are required.');
        }

        return DB::transaction(function () use ($token, $data, $vendorName, $username, $password, $category) {
            $invite = DB::table('vendor_invites')
                ->where('token_hash', self::hashToken(trim($token)))
                ->lockForUpdate()
                ->first();
            if (! $invite || self::status($invite) !== 'pending') {
                throw new RuntimeException(self::INVALID_LINK_MESSAGE);
            }

            $taken = DB::table('users')->where('username', $username)->exists()
                || DB::table('vendors')->where('username', $username)->exists();
            if ($taken) {
                throw new InvalidArgumentException('That username is already taken. Choose another one.');
            }

            $now = now();
            $vendor = [
                'id' => (string) Str::uuid(),
                'vendor_name' => $vendorName,
                'category' => $category,
                'email' => trim((string) ($data['email'] ?? '')),
                'description' => trim((string) ($data['description'] ?? '')),
                'address' => trim((string) ($data['address'] ?? '')),
                'phone' => trim((string) ($data['phone'] ?? '')),
                'whatsapp' => trim((string) ($data['whatsapp'] ?? '')),
                'pictures' => json_encode([]),
                'packages' => json_encode([]),
                'username' => $username,
                'password' => Hash::make($password),
                'status' => 'Active',
                'company_id' => $invite->company_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            DB::table('vendors')->insert(array_intersect_key($vendor, array_flip(Schema::getColumnListing('vendors'))));

            $claimed = DB::table('vendor_invites')
                ->where('id', $invite->id)
                ->whereNull('used_at')
                ->update(['used_at' => $now, 'vendor_id' => $vendor['id'], 'updated_at' => $now]);
            if ($claimed !== 1) {
                throw new RuntimeException(self::INVALID_LINK_MESSAGE);
            }

            unset($vendor['password']);

            return $vendor;
        });
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
