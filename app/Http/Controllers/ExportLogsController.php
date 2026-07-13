<?php

namespace App\Http\Controllers;

use App\Models\ExportLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportLogsController extends Controller
{
    /**
     * Build the filtered activity-log query shared by the page and the CSV export.
     */
    private function filteredQuery(Request $request)
    {
        $userId = $request->query('user_id');
        $routeName = $request->query('route_name');
        $category = $request->query('category');
        $search = trim((string) $request->query('search', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = ExportLog::query()->with('user:id,name,email')->latest();

        if ($userId) {
            $query->where('user_id', $userId);
        }
        if ($routeName) {
            $query->where('route_name', $routeName);
        }
        if ($category) {
            $query->where('category', $category);
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

        return $query;
    }

    /**
     * Display the export logs page with filters.
     */
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $routeName = $request->query('route_name');
        $category = $request->query('category');
        $search = trim((string) $request->query('search', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $perPageParam = $request->query('per_page', '25');
        $perPage = in_array($perPageParam, ['25', '50', '100'], true) ? (int) $perPageParam : 25;

        $logs = $this->filteredQuery($request)->paginate($perPage)->withQueryString();

        $routeNames = ExportLog::query()
            ->whereNotNull('route_name')
            ->distinct()
            ->orderBy('route_name')
            ->pluck('route_name');

        $categories = ExportLog::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

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
                'category' => $category,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => (string) $perPage,
            ],
            'routeNames' => $routeNames,
            'categories' => $categories,
            'users' => $users,
        ]);
    }

    /**
     * Stream all matching activity logs (respecting the current filters) as a CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($request);

        $columns = [
            'id',
            'created_at',
            'category',
            'status',
            'user_id',
            'user_name',
            'user_email',
            'route_name',
            'method',
            'url',
            'target_type',
            'target_id',
            'row_count',
            'file_name',
            'ip_address',
            'user_agent',
            'params',
        ];

        $filename = 'activity-logs-'.now()->format('Y-m-d_His').'.csv';

        $response = new StreamedResponse(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens accented characters correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->id,
                        optional($row->created_at)->toDateTimeString(),
                        $row->category,
                        $row->status,
                        $row->user_id,
                        $row->user_name_snapshot ?? $row->user?->name,
                        $row->user_email_snapshot ?? $row->user?->email,
                        $row->route_name,
                        $row->method,
                        $row->url,
                        $row->target_type,
                        $row->target_id,
                        $row->row_count,
                        $row->file_name,
                        $row->ip_address,
                        $row->user_agent,
                        $row->params ? json_encode($row->params) : null,
                    ]);
                }
            });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }
}
