<?php

namespace App\Http\Controllers;

use App\Models\ExportLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExportLogsController extends Controller
{
    /**
     * Display the export logs page with filters.
     */
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $routeName = $request->query('route_name');
        $search = trim((string) $request->query('search', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $perPageParam = $request->query('per_page', '25');
        $perPage = in_array($perPageParam, ['25', '50', '100'], true) ? (int) $perPageParam : 25;

        $query = ExportLog::query()->with('user:id,name,email')->latest();

        if ($userId) {
            $query->where('user_id', $userId);
        }
        if ($routeName) {
            $query->where('route_name', $routeName);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('user_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('user_email_snapshot', 'like', "%{$search}%")
                    ->orWhere('route_name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $logs = $query->paginate($perPage)->withQueryString();

        $routeNames = ExportLog::query()
            ->whereNotNull('route_name')
            ->distinct()
            ->orderBy('route_name')
            ->pluck('route_name');

        $users = ExportLog::query()
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->select('user_id')
            ->distinct()
            ->get()
            ->map(fn ($r) => ['id' => $r->user_id, 'name' => $r->user?->name ?? 'Unknown'])
            ->values();

        return Inertia::render('Admin/ExportLogs', [
            'logs' => $logs,
            'filters' => [
                'user_id' => $userId,
                'route_name' => $routeName,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => (string) $perPage,
            ],
            'routeNames' => $routeNames,
            'users' => $users,
        ]);
    }
}
