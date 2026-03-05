<?php

namespace App\Http\Controllers;

use App\Models\McDashboardSnapshot;
use App\Services\MailchimpService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class McDashboardController extends Controller
{
    const ANZ_FILM_TAG_MAP = [
        'FILM TOUR - WARREN MILLER' => 'Warren Miller',
        'FILM TOUR - OCEAN' => 'OOFT',
        'FILM TOUR - FLY FISHING' => 'RISE',
        'FILM TOUR - RUNNATION' => 'Run Nation',
        'FILM TOUR - WAFT' => 'WAFT',
        'FILM TOUR - REEL ROCK' => 'Reel Rock',
        'FILM TOUR - MOUNTAINFILM' => 'MFOT',
        'FILM TOUR - CLIMBING FILM TOUR' => 'VLFT/CFT',
    ];

    const ANZ_MAG_TAG_MAP = [
        'MAG - WILD' => 'Wild',
        'MAG - VL' => 'VL',
        'MAG - TRM' => 'TRM',
        'MAG - AMB' => 'AMB',
        'MAG - CHILLFACTOR' => 'CF',
        'MAG - SNOW ACTION' => 'SA',
    ];

    // USA film tags — counted from the "Adventure Film" audience
    const USA_FILM_TAG_MAP = [
        'FILM TOUR - KENDAL MOUNTAIN TOUR' => 'KMT',
        'FILM TOUR - WAFT' => 'WAFT',
        'FILM TOUR - OCEAN' => 'OOFT',
        'FILM TOUR - CLIMBING FILM TOUR' => 'CFT',
    ];

    // F3T = "Fly Fishing Film Tour" audience list subscriber count (matched by name)
    const USA_F3T_AUDIENCE_NAME = 'Fly Fishing Film Tour';

    const ANZ_FILM_KEYS = ['FILM-ANZ', 'Warren Miller', 'OOFT', 'Run Nation', 'WAFT', 'RISE', 'Reel Rock', 'MFOT', 'VLFT/CFT'];
    const ANZ_MAG_KEYS = ['MAGS', 'Wild', 'VL', 'TRM', 'AMB', 'CF', 'SA'];
    const USA_FILM_KEYS = ['FILM-USA', 'F3T', 'KMT', 'WAFT', 'OOFT', 'CFT'];

    public function index()
    {
        $snapshots = McDashboardSnapshot::orderBy('snapshot_date', 'desc')->get();

        $anzKeys = [];
        $usaKeys = [];
        $hasFilmTags = false;
        $hasMagTags = false;
        $hasUsaFilmTags = false;

        foreach ($snapshots as $s) {
            if ($s->audiences && is_array($s->audiences)) {
                if ($s->account === 'anz') {
                    foreach (array_keys($s->audiences) as $k) {
                        $anzKeys[$k] = true;
                    }
                } else {
                    foreach (array_keys($s->audiences) as $k) {
                        $usaKeys[$k] = true;
                    }
                }
            }
            if ($s->account === 'anz' && $s->film_tags && is_array($s->film_tags) && count($s->film_tags) > 0) {
                $hasFilmTags = true;
            }
            if ($s->mag_tags && is_array($s->mag_tags) && count($s->mag_tags) > 0) {
                $hasMagTags = true;
            }
            if ($s->account === 'usa' && $s->film_tags && is_array($s->film_tags) && count($s->film_tags) > 0) {
                $hasUsaFilmTags = true;
            }
        }

        $grouped = [];
        foreach ($snapshots as $s) {
            $date = $s->snapshot_date->toDateString();
            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'snapshot_date' => $date,
                    'period_label' => $s->period_label,
                    'total' => 0,
                    'au_total' => 0,
                    'us_total' => 0,
                    'anz_audiences' => [],
                    'usa_audiences' => [],
                    'anz_film_tags' => [],
                    'anz_mag_tags' => [],
                    'usa_film_tags' => [],
                    'ids' => [],
                ];
            }
            $grouped[$date]['ids'][] = $s->id;
            if ($s->account === 'anz') {
                $grouped[$date]['au_total'] = $s->total;
                $grouped[$date]['anz_audiences'] = $s->audiences ?? [];
                $grouped[$date]['anz_film_tags'] = $s->film_tags ?? [];
                $grouped[$date]['anz_mag_tags'] = $s->mag_tags ?? [];
                $grouped[$date]['period_label'] = $s->period_label;
            } else {
                $grouped[$date]['us_total'] = $s->total;
                $grouped[$date]['usa_audiences'] = $s->audiences ?? [];
                $grouped[$date]['usa_film_tags'] = $s->film_tags ?? [];
            }
            $grouped[$date]['total'] = $grouped[$date]['au_total'] + $grouped[$date]['us_total'];
        }

        return Inertia::render('McDashboard', [
            'rows' => array_values($grouped),
            'anzKeys' => array_keys($anzKeys),
            'usaKeys' => array_keys($usaKeys),
            'anzFilmKeys' => $hasFilmTags ? self::ANZ_FILM_KEYS : [],
            'anzMagKeys' => $hasMagTags ? self::ANZ_MAG_KEYS : [],
            'usaFilmKeys' => $hasUsaFilmTags ? self::USA_FILM_KEYS : [],
        ]);
    }

    public function snapshotNow()
    {
        $today = now();
        $periodLabel = $today->format('F j, Y');
        $results = [];

        foreach (['anz', 'usa'] as $account) {
            try {
                $service = new MailchimpService($account);
                $lists = $service->getLists();

                $audiences = [];
                $total = 0;
                $filmTags = [];
                $magTags = [];

                foreach ($lists as $list) {
                    $count = $list['stats']['member_count'] ?? 0;
                    $listName = $list['name'];
                    $audiences[$listName] = $count;
                    $total += $count;

                    // USA: F3T = Fly Fishing Film Tour audience count
                    if ($account === 'usa' && $listName === self::USA_F3T_AUDIENCE_NAME) {
                        $filmTags['F3T'] = $count;
                    }

                    try {
                        $segments = $service->getListSegments($list['id']);
                        foreach ($segments as $segment) {
                            $segName = $segment['name'] ?? '';
                            $memberCount = $segment['member_count'] ?? 0;

                            if ($account === 'anz') {
                                if (isset(self::ANZ_FILM_TAG_MAP[$segName])) {
                                    $displayName = self::ANZ_FILM_TAG_MAP[$segName];
                                    $filmTags[$displayName] = ($filmTags[$displayName] ?? 0) + $memberCount;
                                }
                                if (isset(self::ANZ_MAG_TAG_MAP[$segName])) {
                                    $displayName = self::ANZ_MAG_TAG_MAP[$segName];
                                    $magTags[$displayName] = ($magTags[$displayName] ?? 0) + $memberCount;
                                }
                            }

                            if ($account === 'usa' && isset(self::USA_FILM_TAG_MAP[$segName])) {
                                $displayName = self::USA_FILM_TAG_MAP[$segName];
                                $filmTags[$displayName] = ($filmTags[$displayName] ?? 0) + $memberCount;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("MC Dashboard: failed to fetch segments for list {$listName}: " . $e->getMessage());
                    }
                }

                if ($account === 'anz' && !empty($filmTags)) {
                    $filmTags = array_merge(['FILM-ANZ' => array_sum($filmTags)], $filmTags);
                }

                if ($account === 'anz' && !empty($magTags)) {
                    $magTags = array_merge(['MAGS' => array_sum($magTags)], $magTags);
                }

                if ($account === 'usa' && !empty($filmTags)) {
                    $filmTags = array_merge(['FILM-USA' => array_sum($filmTags)], $filmTags);
                }

                McDashboardSnapshot::create([
                    'account' => $account,
                    'period_label' => $periodLabel,
                    'snapshot_date' => $today->toDateString(),
                    'audiences' => $audiences,
                    'film_tags' => !empty($filmTags) ? $filmTags : null,
                    'mag_tags' => $account === 'anz' ? ($magTags ?: null) : null,
                    'total' => $total,
                    'au_total' => $account === 'anz' ? $total : 0,
                    'us_total' => $account === 'usa' ? $total : 0,
                ]);

                $results[] = strtoupper($account) . ": {$total}";
            } catch (\Exception $e) {
                $results[] = strtoupper($account) . ": FAILED - " . $e->getMessage();
            }
        }

        return redirect()->back()->with('success', 'Snapshot saved. ' . implode(' | ', $results));
    }

    public function destroy(McDashboardSnapshot $snapshot)
    {
        McDashboardSnapshot::where('snapshot_date', $snapshot->snapshot_date)->delete();

        return redirect()->back()->with('success', 'Snapshot row deleted.');
    }
}
