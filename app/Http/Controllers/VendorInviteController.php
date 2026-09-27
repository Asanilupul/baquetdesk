<?php

namespace App\Http\Controllers;

use App\Support\ApiPermissions;
use App\Support\CompanyApiSession;
use App\Support\VendorInvites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class VendorInviteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $session = null;
        if ($deny = $this->denyUnlessVendorManager($request, $session)) {
            return $deny;
        }

        return response()->json(['data' => VendorInvites::listForCompany($session['company_id']), 'error' => null]);
    }

    public function store(Request $request): JsonResponse
    {
        $session = null;
        if ($deny = $this->denyUnlessVendorManager($request, $session)) {
            return $deny;
        }

        try {
            $created = VendorInvites::create(
                $session['company_id'],
                $session['user_id'],
                $session['username'],
                (string) $request->input('note', ''),
            );

            return response()->json(['data' => $created['invite'] + ['link' => $created['link']], 'error' => null]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Create vendor invite');
        }
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $session = null;
        if ($deny = $this->denyUnlessVendorManager($request, $session)) {
            return $deny;
        }

        $revoked = DB::table('vendor_invites')
            ->where('id', $id)
            ->where('company_id', $session['company_id'])
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($revoked !== 1) {
            return response()->json(['data' => null, 'error' => ['message' => 'Link not found or already used / revoked.']], 404);
        }

        return response()->json(['data' => ['ok' => true], 'error' => null]);
    }

    public function check(Request $request): JsonResponse
    {
        $invite = VendorInvites::findUsable((string) $request->query('token', ''));
        if (! $invite) {
            return response()->json(['data' => null, 'error' => ['message' => VendorInvites::INVALID_LINK_MESSAGE]], 404);
        }

        return response()->json([
            'data' => [
                'company_name' => (string) DB::table('companies')->where('id', $invite->company_id)->value('name'),
                'categories' => VendorInvites::categoriesFor($invite->company_id),
                'expires_at' => $invite->expires_at ? (string) $invite->expires_at : null,
            ],
            'error' => null,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $limiterKey = 'vendor-invite-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 10)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Too many registration attempts from this network. Try again later.'],
            ], 429);
        }
        RateLimiter::hit($limiterKey, 3600);

        try {
            $vendor = VendorInvites::register((string) $request->input('token', ''), $request->only([
                'vendor_name', 'category', 'email', 'description', 'address', 'phone', 'whatsapp', 'username', 'password',
            ]));

            return response()->json([
                'data' => ['id' => $vendor['id'], 'vendor_name' => $vendor['vendor_name'], 'username' => $vendor['username']],
                'error' => null,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Vendor invite registration', 410);
        }
    }

    /**
     * @param  array{user_id: string, company_id: string, role: string, username: string}|null  $session
     */
    private function denyUnlessVendorManager(Request $request, ?array &$session = null): ?JsonResponse
    {
        $session = CompanyApiSession::fromRequest($request);
        if ($session === null || $session['company_id'] === '') {
            return response()->json(['data' => null, 'error' => ['message' => 'Authentication required. Please log in again.']], 401);
        }

        $actor = ApiPermissions::actor($session);
        if ($actor === null || ! ApiPermissions::canUseModule($actor, 'vendors')) {
            return response()->json(['data' => null, 'error' => ['message' => 'You do not have permission to manage vendors.']], 403);
        }

        return null;
    }
}
