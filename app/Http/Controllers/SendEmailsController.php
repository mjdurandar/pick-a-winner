<?php

namespace App\Http\Controllers;

use App\Exceptions\NothingToSendException;
use App\Http\Requests\SendEmailNowRequest;
use App\Mail\FilmSiteDifferencesFound;
use App\Mail\SheetChangesAwaitingApproval;
use App\Mail\WeeklyDigest;
use App\Models\FilmSiteCheck;
use App\Models\SheetSource;
use App\Services\ManualMailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;

/**
 * "Send emails": the film site check, master sheet sync and weekly digest
 * alerts, sent now on demand.
 *
 * Any mix can be ticked at once, and each is still its own email — one per
 * check, one per tab, one digest — exactly as the scheduler would send them,
 * so a manual send reads the same as an automatic one.
 */
class SendEmailsController extends Controller
{
    public function __construct(protected ManualMailSender $mailer) {}

    /** What the prompt can offer this person, and who each email usually goes to. */
    public function options(Request $request): JsonResponse
    {
        $sheets = (bool) $request->user()?->canManageMasterSheet();

        return response()->json([
            'film' => [
                'available' => true,
                'items' => FilmSiteCheck::orderBy('id')->get()->map(fn (FilmSiteCheck $c) => [
                    'id' => $c->id,
                    'label' => $c->label().($c->enabled ? '' : ' (paused)'),
                    'enabled' => $c->enabled,
                ]),
                'defaults' => ManualMailSender::defaults(
                    FilmSiteDifferencesFound::recipients(),
                    FilmSiteDifferencesFound::ccRecipients()
                ),
            ],
            // The master sheet screens are one account's; nobody else is offered
            // an email pointing them at a review screen they cannot open.
            'sheet' => [
                'available' => $sheets,
                'items' => $sheets
                    ? SheetSource::with('event')->orderBy('id')->get()->map(fn (SheetSource $s) => [
                        'id' => $s->id,
                        'label' => $s->tab_name.($s->event ? ' — '.$s->event->event_name : '').($s->enabled ? '' : ' (paused)'),
                        'enabled' => $s->enabled,
                    ])
                    : [],
                'defaults' => ManualMailSender::defaults(SheetChangesAwaitingApproval::recipients(), []),
            ],
            'weekly' => [
                'available' => true,
                'defaults' => ManualMailSender::defaults(WeeklyDigest::recipients(), WeeklyDigest::ccRecipients()),
            ],
        ]);
    }

    public function send(SendEmailNowRequest $request): JsonResponse
    {
        // Every check and tab is re-read inline before it is mailed, and "all"
        // can mean a dozen websites.
        set_time_limit(300);

        $results = [];

        // Built lazily, one at a time: each closure re-reads its source just
        // before its email goes, so a slow site does not age the others' data.
        foreach ($this->selected($request) as [$label, $build]) {
            $results[] = $this->attempt($request, $label, $build);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * The emails asked for, as [label, fn(): Mailable] pairs.
     *
     * @return list<array{string, \Closure(): Mailable}>
     */
    protected function selected(SendEmailNowRequest $request): array
    {
        $user = $request->user();
        $emails = [];

        if ($film = $request->validated('film_check')) {
            // "All" means the checks the scheduler runs; a paused one is paused
            // because nobody wants to hear about it.
            $checks = $film === 'all'
                ? FilmSiteCheck::enabled()->orderBy('id')->get()
                : FilmSiteCheck::whereKey($film)->get();

            foreach ($checks as $check) {
                $emails[] = ['Film site check: '.$check->label(), fn () => $this->mailer->filmCheck($check, $user)];
            }
        }

        if (($sheet = $request->validated('sheet_source')) && $user?->canManageMasterSheet()) {
            $sources = $sheet === 'all'
                ? SheetSource::enabled()->orderBy('id')->get()
                : SheetSource::whereKey($sheet)->get();

            foreach ($sources as $source) {
                $emails[] = ['Master sheet sync: '.$source->tab_name, fn () => $this->mailer->sheetSource($source)];
            }
        }

        if ($period = $request->validated('weekly_period')) {
            $emails[] = [
                'Weekly digest: '.($period === ManualMailSender::WEEK_LAST ? 'last week' : 'this week so far'),
                fn () => $this->mailer->weeklyDigest($period),
            ];
        }

        return $emails;
    }

    /**
     * @param  \Closure(): Mailable  $build
     * @return array{email: string, status: string, message: string}
     */
    protected function attempt(SendEmailNowRequest $request, string $label, \Closure $build): array
    {
        try {
            $this->mailer->send($build(), $request->recipients(), $request->ccRecipients(), $request->user(), $label);
        } catch (NothingToSendException $e) {
            return ['email' => $label, 'status' => 'skipped', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['email' => $label, 'status' => 'failed', 'message' => $e->getMessage()];
        }

        return ['email' => $label, 'status' => 'sent', 'message' => 'Sent.'];
    }
}
