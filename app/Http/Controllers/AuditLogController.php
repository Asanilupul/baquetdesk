<?php

namespace App\Http\Controllers;

use App\Support\CompanyApiSession;
use App\Support\CompanySubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLogController extends Controller
{
    private const MAX_ROWS = 2000;

    /**
     * Company audit trail filtered by date range, user, action and module (Admin only).
     */
    public function index(Request $request): JsonResponse
    {
        $session = CompanyApiSession::fromRequest($request);
        if ($session === null) {
            return response()->json(['data' => null, 'error' => ['message' => 'Authentication required. Please log in again.']], 401);
        }
        if ($session['role'] !== 'Admin') {
            return response()->json(['data' => null, 'error' => ['message' => 'Only Admins can view the audit log']], 403);
        }

        $companyId = $session['company_id'];
        if ($companyId === '') {
            return response()->json(['data' => null, 'error' => ['message' => 'Company session required.']], 401);
        }

        $company = Schema::hasTable('companies') ? DB::table('companies')->where('id', $companyId)->first() : null;
        if (! CompanySubscription::isUsable($company)) {
            return response()->json(['data' => null, 'error' => ['message' => CompanySubscription::denyMessage($company)]], 403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'username' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:32'],
            'table' => ['nullable', 'string', 'max:64'],
            'tz_offset' => ['nullable', 'integer', 'between:-900,900'],
        ]);

        $tzOffsetMinutes = (int) ($validated['tz_offset'] ?? 0);
        $query = DB::table('audit_logs')->where('company_id', $companyId);

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['from'].' 00:00:00', 'UTC')->addMinutes($tzOffsetMinutes));
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['to'].' 23:59:59', 'UTC')->addMinutes($tzOffsetMinutes));
        }
        if (! empty($validated['username'])) {
            $query->where('username', $validated['username']);
        }
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['table'])) {
            $query->where('table_name', $validated['table']);
        }

        $total = (clone $query)->count();
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get()
            ->map(function ($row) {
                $row = (array) $row;
                $row['changes'] = $row['changes'] !== null ? json_decode((string) $row['changes'], true) : null;
                $row['created_at'] = Carbon::parse($row['created_at'], 'UTC')->toIso8601ZuluString();

                return $row;
            })->all();

        $usernames = DB::table('audit_logs')->where('company_id', $companyId)
            ->whereNotNull('username')->distinct()->orderBy('username')->pluck('username')->all();
        $tables = DB::table('audit_logs')->where('company_id', $companyId)
            ->whereNotNull('table_name')->distinct()->orderBy('table_name')->pluck('table_name')->all();

        return response()->json([
            'data' => [
                'logs' => $logs,
                'total' => $total,
                'limit' => self::MAX_ROWS,
                'usernames' => $usernames,
                'tables' => $tables,
            ],
            'error' => null,
        ]);
    }
}
