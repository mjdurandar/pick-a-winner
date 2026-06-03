<?php

namespace App\Http\Middleware;

use App\Models\ExportLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class LogExports
{
    /**
     * Keys to strip from logged params (defence-in-depth — none of the export
     * endpoints accept secrets, but be safe).
     */
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', '_token', 'api_key', 'secret'];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Runs after the response is sent to the browser. Safe place to do
     * extra work without delaying the download.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            $user = Auth::user();
            $route = $request->route();
            $routeName = $route ? $route->getName() : null;
            $params = $this->collectParams($request);
            [$targetType, $targetId] = $this->inferTarget($route);

            $status = $response->getStatusCode() >= 200 && $response->getStatusCode() < 400
                ? 'success'
                : 'failed';

            ExportLog::create([
                'user_id' => $user?->id,
                'user_name_snapshot' => $user?->name,
                'user_email_snapshot' => $user?->email,
                'route_name' => $routeName,
                'method' => $request->method(),
                'url' => substr($request->fullUrl(), 0, 2048),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'params' => $params,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'row_count' => $request->attributes->get('export_row_count'),
                'file_name' => $request->attributes->get('export_file_name'),
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LogExports middleware failed to record export: ' . $e->getMessage());
        }
    }

    private function collectParams(Request $request): array
    {
        $all = array_merge(
            $request->route()?->parameters() ?? [],
            $request->query(),
            $request->post(),
        );

        foreach (self::SENSITIVE_KEYS as $k) {
            if (array_key_exists($k, $all)) {
                $all[$k] = '[redacted]';
            }
        }

        return array_map(function ($v) {
            if (is_scalar($v) || is_null($v)) {
                return $v;
            }
            if (is_array($v)) {
                return $v;
            }
            return (string) $v;
        }, $all);
    }

    /**
     * @return array{0: ?string, 1: ?string} [target_type, target_id]
     */
    private function inferTarget($route): array
    {
        if (! $route) {
            return [null, null];
        }
        $params = $route->parameters();
        $map = [
            'eventId' => 'event',
            'event' => 'event',
            'event_uuid' => 'event',
            'locationId' => 'location',
            'location' => 'location',
            'filmId' => 'film',
            'film' => 'film',
            'id' => $this->guessTypeFromRouteName($route->getName()),
        ];
        foreach ($map as $param => $type) {
            if (isset($params[$param])) {
                $val = $params[$param];
                $id = is_object($val) && method_exists($val, 'getKey') ? $val->getKey() : (string) $val;
                return [$type, $id];
            }
        }
        return [null, null];
    }

    private function guessTypeFromRouteName(?string $name): string
    {
        if (! $name) {
            return 'unknown';
        }
        if (str_contains($name, 'mailchimpImportLogs')) {
            return 'mailchimp_import_log';
        }
        if (str_contains($name, 'weekly-report')) {
            return 'weekly_report';
        }
        return 'unknown';
    }
}
