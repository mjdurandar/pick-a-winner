<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\Location;
use App\Models\NewsletterResubscribeAttempt;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporting over newsletter resubscribe attempts made by the sign-up form.
 *
 * Two views of the same rows: who came back, and who Mailchimp would not take
 * back. Both group by film, reached through the event the form belonged to.
 */
class NewsletterResubscribeReportController extends Controller
{
    /**
     * @var array<string, string> report key => outcome it covers
     */
    private const REPORTS = [
        'resubscribed' => NewsletterResubscribeAttempt::RESUBSCRIBED,
        'confirmation_sent' => NewsletterResubscribeAttempt::CONFIRMATION_SENT,
        'deferred' => NewsletterResubscribeAttempt::DEFERRED,
        'blocked' => NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
        'failed' => NewsletterResubscribeAttempt::FAILED,
    ];

    /**
     * The on-screen report for one event, or for every event of its film.
     */
    public function index(Request $request, Events $event): Response
    {
        $filters = $this->validateFilters($request);
        $scope = $filters['scope'] ?? 'event';

        $eventIds = $this->scopedEventIds($event, $scope);

        // Tiles ignore the outcome filter so they stay put while you click through
        // them; every other filter still applies.
        $unfiltered = $this->baseQuery($eventIds, array_diff_key($filters, ['report' => null]));

        $attempts = $this->baseQuery($eventIds, $filters)
            ->with(['event:id,event_name,film_id', 'location:id,name'])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (NewsletterResubscribeAttempt $attempt) => [
                'id' => $attempt->id,
                'email' => $attempt->email,
                'outcome' => $attempt->outcome,
                'outcome_label' => $attempt->label(),
                'detail' => $attempt->detail,
                'event_name' => $attempt->event?->event_name,
                'location_name' => $attempt->location?->name,
                'mailchimp_account' => $attempt->mailchimp_account,
                'list_name' => $attempt->list_name,
                'created_at' => $attempt->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('NewsletterResubscribes', [
            'event' => [
                'id' => $event->id,
                'event_name' => $event->event_name,
                'film_id' => $event->film_id,
                'film_name' => $event->film?->name,
            ],
            'scope' => $scope,
            'filters' => [
                'report' => $filters['report'] ?? '',
                'location_id' => $filters['location_id'] ?? '',
                'search' => $filters['search'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'summary' => $this->summary($unfiltered),
            'breakdown' => $scope === 'film'
                ? $this->breakdownByEvent($unfiltered, $eventIds)
                : $this->breakdownByLocation($unfiltered, $event),
            'breakdownBy' => $scope === 'film' ? 'event' : 'location',
            'locations' => Location::where('event_id', $event->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'siblingEventCount' => count($this->scopedEventIds($event, 'film')),
            'attempts' => $attempts,
        ]);
    }

    /**
     * The same rows as the page, streamed as CSV. Takes the page's query string
     * verbatim so the file matches what is on screen.
     */
    public function download(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);

        $eventIds = null;
        $label = 'all';

        if ($eventId = $filters['event_id'] ?? null) {
            $event = Events::findOrFail($eventId);
            $eventIds = $this->scopedEventIds($event, $filters['scope'] ?? 'event');
            $label = str($event->event_name ?: 'event-'.$event->id)->slug()->value();
        } elseif ($filmId = $filters['film_id'] ?? null) {
            // Kept for direct links predating the page: filter by film alone.
            $eventIds = Events::where('film_id', $filmId)->pluck('id')->all();
            $label = 'film-'.$filmId;
        }

        $query = $this->baseQuery($eventIds, $filters)
            ->with(['event.film', 'location'])
            // Grouped in the file as well as on the page, so a film's rows read
            // together rather than interleaved by date.
            ->orderBy('event_id')
            ->orderBy('created_at');

        $report = $filters['report'] ?? null;
        $filename = 'newsletter-resubscribes-'.$label.'-'.($report ?? 'all').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Film', 'Event', 'Location', 'Email', 'Outcome', 'Reason',
                'Mailchimp account', 'Audience', 'Attempted at',
            ]);

            $query->chunk(1000, function ($attempts) use ($handle) {
                foreach ($attempts as $attempt) {
                    fputcsv($handle, [
                        $attempt->event?->film?->name ?? '—',
                        $attempt->event?->event_name ?? '—',
                        $attempt->location?->name ?? '—',
                        $attempt->email,
                        $attempt->label(),
                        $attempt->detail,
                        $attempt->mailchimp_account,
                        $attempt->list_name,
                        $attempt->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'scope' => ['nullable', 'string', 'in:event,film'],
            'report' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::REPORTS))],
            'event_id' => ['nullable', 'integer'],
            'film_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }

    /**
     * Every event the report covers. Film scope falls back to the single event
     * when the event has no film attached.
     *
     * @return array<int, int>
     */
    private function scopedEventIds(Events $event, string $scope): array
    {
        if ($scope !== 'film' || ! $event->film_id) {
            return [$event->id];
        }

        return Events::where('film_id', $event->film_id)->pluck('id')->all();
    }

    /**
     * @param  array<int, int>|null  $eventIds  null means every event
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(?array $eventIds, array $filters): Builder
    {
        return NewsletterResubscribeAttempt::query()
            ->when($eventIds !== null, fn ($q) => $q->whereIn('event_id', $eventIds))
            ->when(
                $filters['report'] ?? null,
                fn ($q, $report) => $q->where('outcome', self::REPORTS[$report])
            )
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when(
                $filters['search'] ?? null,
                fn ($q, $search) => $q->where('email', 'like', '%'.$search.'%')
            )
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to));
    }

    /**
     * Attempts and distinct contacts per outcome.
     *
     * Both are reported because they answer different questions. A contact in
     * compliance state is recorded twice — once on the email check, once on submit —
     * so counting rows alone overstates how many people were blocked.
     *
     * @return array<string, mixed>
     */
    private function summary(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('outcome, COUNT(*) as attempts, COUNT(DISTINCT email) as contacts')
            ->groupBy('outcome')
            ->get()
            ->keyBy('outcome');

        $summary = [];
        foreach (NewsletterResubscribeAttempt::OUTCOME_LABELS as $outcome => $label) {
            $summary[$outcome] = [
                'label' => $label,
                'attempts' => (int) ($rows[$outcome]->attempts ?? 0),
                'contacts' => (int) ($rows[$outcome]->contacts ?? 0),
            ];
        }

        $summary['total'] = [
            'label' => 'All attempts',
            'attempts' => array_sum(array_column($summary, 'attempts')),
            'contacts' => (int) (clone $query)->distinct()->count('email'),
        ];

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breakdownByLocation(Builder $query, Events $event): array
    {
        $counts = (clone $query)
            ->selectRaw('location_id, outcome, COUNT(*) as attempts')
            ->groupBy('location_id', 'outcome')
            ->get();

        $locations = Location::where('event_id', $event->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = $locations
            ->map(fn ($location) => $this->breakdownRow($location->id, $location->name, $counts, 'location_id'))
            ->all();

        // Attempts made on the email check happen before a location is picked, so
        // they carry no location. They are still part of the total.
        if ($counts->whereNull('location_id')->isNotEmpty()) {
            $rows[] = $this->breakdownRow(null, 'Before location was chosen', $counts, 'location_id');
        }

        return $this->sortBreakdown($rows);
    }

    /**
     * @param  array<int, int>  $eventIds
     * @return array<int, array<string, mixed>>
     */
    private function breakdownByEvent(Builder $query, array $eventIds): array
    {
        $counts = (clone $query)
            ->selectRaw('event_id, outcome, COUNT(*) as attempts')
            ->groupBy('event_id', 'outcome')
            ->get();

        $events = Events::whereIn('id', $eventIds)->orderBy('event_name')->get(['id', 'event_name']);

        $rows = $events
            ->map(fn ($event) => $this->breakdownRow($event->id, $event->event_name, $counts, 'event_id'))
            ->all();

        return $this->sortBreakdown($rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $counts
     * @return array<string, mixed>
     */
    private function breakdownRow(?int $id, ?string $name, $counts, string $key): array
    {
        // whereNull rather than where(..., null): a loose comparison would also
        // match a 0 id.
        $mine = $id === null ? $counts->whereNull($key) : $counts->where($key, $id);

        $row = [
            'id' => $id,
            'name' => $name ?: '—',
            'total' => (int) $mine->sum('attempts'),
        ];

        foreach (array_keys(NewsletterResubscribeAttempt::OUTCOME_LABELS) as $outcome) {
            $row[$outcome] = (int) $mine->where('outcome', $outcome)->sum('attempts');
        }

        return $row;
    }

    /**
     * Busiest first — a report read top-down should start where the action is.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortBreakdown(array $rows): array
    {
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $rows;
    }
}
