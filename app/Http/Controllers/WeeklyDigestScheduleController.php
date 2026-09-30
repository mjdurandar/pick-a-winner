<?php

namespace App\Http\Controllers;

use App\Support\WeeklyDigestSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * When the automated weekly digest is sent. Admin only, from the weekly report
 * screen. The default (from .env) is always shown next to the form, and reset
 * goes back to it.
 */
class WeeklyDigestScheduleController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->respond();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'day' => 'required|integer|between:0,6',
            'time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'timezone' => 'required|timezone:all',
        ], [
            'time.regex' => 'Enter the time as HH:MM, 24-hour.',
            'timezone.timezone' => 'That is not a timezone PHP knows.',
        ]);

        $schedule = WeeklyDigestSchedule::save((int) $data['day'], $data['time'], $data['timezone']);

        Log::info('Weekly digest schedule changed', [
            'to' => $schedule->describe(),
            'by' => $request->user()?->email,
        ]);

        return $this->respond();
    }

    public function reset(Request $request): JsonResponse
    {
        $schedule = WeeklyDigestSchedule::reset();

        Log::info('Weekly digest schedule reset to default', [
            'to' => $schedule->describe(),
            'by' => $request->user()?->email,
        ]);

        return $this->respond();
    }

    protected function respond(): JsonResponse
    {
        $current = WeeklyDigestSchedule::current();

        return response()->json([
            'schedule' => $current->toResponse(),
            'default' => WeeklyDigestSchedule::default()->toResponse(),
            'overridden' => WeeklyDigestSchedule::isOverridden(),
            'days' => WeeklyDigestSchedule::DAYS,
            // The picker's list, plus whatever is saved if it was typed in
            // from outside it, so the current value is always selectable.
            'timezones' => array_values(array_unique(array_merge(WeeklyDigestSchedule::TIMEZONES, [$current->timezone]))),
        ]);
    }
}
