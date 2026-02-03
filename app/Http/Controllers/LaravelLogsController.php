<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class LaravelLogsController extends Controller
{
    /** Allowed line limits for the view */
    public const ALLOWED_LINES = [500, 1000, 2000];

    /**
     * Resolve the main Laravel log file path (single or latest daily).
     */
    private function logFilePath(): string
    {
        $path = config('logging.channels.single.path') ?? storage_path('logs/laravel.log');
        if (File::exists($path)) {
            return $path;
        }
        $dir = dirname($path);
        $prefix = basename($path);
        $prefix = preg_replace('/\.log$/', '', $prefix) . '-';
        $files = File::glob($dir . '/' . $prefix . '*.log');
        if ($files !== false && count($files) > 0) {
            rsort($files);

            return $files[0];
        }

        return $path;
    }

    /**
     * Read the last N lines from the log file. Returns [content, totalLineCount].
     */
    private function readLastLines(string $path, int $lines): array
    {
        if (! File::exists($path)) {
            return ['', 0];
        }
        $content = File::get($path);
        $all = explode("\n", $content);
        $total = count($all);
        $last = array_slice($all, -$lines);

        return [implode("\n", $last), $total];
    }

    /**
     * Show the Laravel logs page.
     */
    public function index(Request $request)
    {
        $lines = (int) $request->query('lines', 1000);
        if (! in_array($lines, self::ALLOWED_LINES, true)) {
            $lines = 1000;
        }
        $path = $this->logFilePath();
        [$content, $totalLines] = $this->readLastLines($path, $lines);

        return Inertia::render('LaravelLogs', [
            'logContent' => $content,
            'linesLimit' => $lines,
            'totalLines' => $totalLines,
            'logPath' => $path,
        ]);
    }

    /**
     * Clear the Laravel log file.
     */
    public function clear(Request $request)
    {
        $path = $this->logFilePath();
        if (! File::exists($path)) {
            return response()->json(['success' => true, 'message' => 'Log file already empty or missing.']);
        }
        File::put($path, '');
        return response()->json(['success' => true, 'message' => 'Log file cleared.']);
    }
}
