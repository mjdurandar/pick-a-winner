<?php

namespace App\Http\Controllers;

use App\Listeners\AddAlwaysCcRecipient;
use App\Support\WeeklyDigestRecipients;
use App\Support\WeeklyDigestSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * When the automated weekly digest is sent, and who it goes to. Admin only,
 * from the weekly report screen. The default (from .env) is always shown next
 * to the form, and reset goes back to it.
 */
class WeeklyDigestScheduleController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->respond();
    }

    public function update(Request $request): JsonResponse
    {
        // To and CC arrive as typed — comma, semicolon or newline separated —
        // so a typo is reported against the address that is wrong.
        $request->merge([
            'to' => self::split($request->input('to')),
            'cc' => self::split($request->input('cc')),
        ]);

        $data = $request->validate([
            'day' => 'required|integer|between:0,6',
            'time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'timezone' => 'required|timezone:all',
            'to' => 'required|array|min:1|max:20',
            'to.*' => 'email',
            'cc' => 'array|max:20',
            'cc.*' => 'email',
        ], [
            'time.regex' => 'Enter the time as HH:MM, 24-hour.',
            'timezone.timezone' => 'That is not a timezone PHP knows.',
            'to.required' => 'Enter at least one address to send to.',
            'to.*.email' => ':input is not an email address.',
            'cc.*.email' => ':input is not an email address.',
        ]);

        // Saving the default values is the same as resetting: no override row
        // is left behind, so a later change to .env still takes effect.
        $schedule = new WeeklyDigestSchedule((int) $data['day'], sprintf('%02d:%02d', ...explode(':', $data['time'])), $data['timezone']);
        $schedule = $schedule->toArray() === WeeklyDigestSchedule::default()->toArray()
            ? WeeklyDigestSchedule::reset()
            : WeeklyDigestSchedule::save($schedule->day, $schedule->time, $schedule->timezone);

        // Anyone in To would only get a second copy from CC.
        $to = $data['to'];
        $cc = array_values(array_udiff($data['cc'] ?? [], $to, 'strcasecmp'));
        $recipients = new WeeklyDigestRecipients($to, $cc);
        $recipients = $recipients->matches(WeeklyDigestRecipients::default())
            ? WeeklyDigestRecipients::reset()
            : WeeklyDigestRecipients::save($to, $cc);

        Log::info('Weekly digest settings changed', [
            'schedule' => $schedule->describe(),
            'to' => $recipients->to,
            'cc' => $recipients->cc,
            'by' => $request->user()?->email,
        ]);

        return $this->respond();
    }

    public function reset(Request $request): JsonResponse
    {
        $schedule = WeeklyDigestSchedule::reset();
        $recipients = WeeklyDigestRecipients::reset();

        Log::info('Weekly digest settings reset to default', [
            'schedule' => $schedule->describe(),
            'to' => $recipients->to,
            'cc' => $recipients->cc,
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
            'recipients' => WeeklyDigestRecipients::current()->toArray(),
            'default_recipients' => WeeklyDigestRecipients::default()->toArray(),
            'recipients_overridden' => WeeklyDigestRecipients::isOverridden(),
            // Copied on every mail by the send listener, whatever is saved here.
            'always_cc' => AddAlwaysCcRecipient::addresses(),
        ]);
    }

    /** @return list<string> */
    protected static function split(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $parts = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($parts));
    }
}
