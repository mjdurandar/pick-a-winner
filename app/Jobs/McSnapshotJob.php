<?php

namespace App\Jobs;

use App\Http\Controllers\McDashboardController;
use App\Models\McDashboardSnapshot;
use App\Services\MailchimpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class McSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $today = now();
        $periodLabel = $today->format('F j, Y');

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

                    if ($account === 'usa' && $listName === McDashboardController::USA_F3T_AUDIENCE_NAME) {
                        $filmTags['F3T'] = $count;
                    }

                    try {
                        $segments = $service->getListSegments($list['id']);
                        foreach ($segments as $segment) {
                            $segName = $segment['name'] ?? '';
                            $memberCount = $segment['member_count'] ?? 0;

                            if ($account === 'anz') {
                                if (isset(McDashboardController::ANZ_FILM_TAG_MAP[$segName])) {
                                    $displayName = McDashboardController::ANZ_FILM_TAG_MAP[$segName];
                                    $filmTags[$displayName] = ($filmTags[$displayName] ?? 0) + $memberCount;
                                }
                                if (isset(McDashboardController::ANZ_MAG_TAG_MAP[$segName])) {
                                    $displayName = McDashboardController::ANZ_MAG_TAG_MAP[$segName];
                                    $magTags[$displayName] = ($magTags[$displayName] ?? 0) + $memberCount;
                                }
                            }

                            if ($account === 'usa' && isset(McDashboardController::USA_FILM_TAG_MAP[$segName])) {
                                $displayName = McDashboardController::USA_FILM_TAG_MAP[$segName];
                                $filmTags[$displayName] = ($filmTags[$displayName] ?? 0) + $memberCount;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("MC Snapshot Job: failed to fetch segments for list {$listName}: " . $e->getMessage());
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

                Log::info("MC Dashboard snapshot saved for {$account}", ['total' => $total]);
            } catch (\Exception $e) {
                Log::error("MC Dashboard snapshot failed for {$account}: " . $e->getMessage());
            }
        }
    }
}
