<?php

/**
 * READ-ONLY spike: can the Win App read the shows listed on a film site, and do
 * they agree with our locations?
 *
 * Nothing here writes — not to our database, not to the film site. It fetches,
 * compares, and prints. Deliberately a script rather than a job or a command:
 * the point is to find out what the film site will give us before anything is
 * built on top of it.
 *
 *   php scripts/check-film-site.php
 *   php scripts/check-film-site.php --event=12 --site=https://runnationfilmfestival.com --region=au --year=2026
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Location;
use Illuminate\Support\Facades\Http;

// ---- options ---------------------------------------------------------------

$opt = getopt('', ['event::', 'site::', 'region::', 'year::']);
$eventId = (int) ($opt['event'] ?? 12);                                   // RunNation 2026
$site = rtrim($opt['site'] ?? 'https://runnationfilmfestival.com', '/');
$regionSlug = $opt['region'] ?? 'au';
$year = (string) ($opt['year'] ?? 2026);

$event = App\Models\Events::find($eventId);
if (! $event) {
    exit("No event with id {$eventId}\n");
}

echo "Film site : {$site}\n";
echo "Win App   : {$event->event_name} (event {$eventId})\n";
echo "Filter    : region={$regionSlug} season={$year}\n\n";

$get = function (string $path, array $query = []) use ($site) {
    $r = Http::timeout(30)->acceptJson()->get($site.$path, $query);

    return $r->successful() ? $r->json() : null;
};

// ---- 1. can we reach the site's API at all? --------------------------------

$types = $get('/wp-json/wp/v2/types');
if (! $types) {
    exit("FAIL: {$site}/wp-json is not readable.\n");
}
echo '✓ REST API readable — post types include: '.implode(', ', array_slice(array_keys($types), -6))."\n";

// ---- 2. resolve the taxonomy ids this site uses ----------------------------

$termId = function (string $tax, string $match) use ($get) {
    foreach ($get("/wp-json/wp/v2/{$tax}", ['per_page' => 100, '_fields' => 'id,name,slug']) ?? [] as $t) {
        if (strcasecmp($t['slug'], $match) === 0 || strcasecmp($t['name'], $match) === 0) {
            return $t['id'];
        }
    }

    return null;
};

$regionId = $termId('region', $regionSlug);
$seasonId = $termId('season', $year);
echo "✓ Taxonomies resolved — region '{$regionSlug}'=".($regionId ?? '?').", season '{$year}'=".($seasonId ?? '?')."\n";

// ---- 3. the shows the site is publishing -----------------------------------

// Two sources, because neither is complete on its own:
//   wp/v2/session      — every show, filterable by region/season, but no dates
//                        (the date fields are ACF and not published to REST)
//   adv-film/get-sessions — the site's own endpoint: fewer rows on some sites,
//                        but carries date, venue, ticket status and kind.
// Whatever get-sessions gives is preferred; the rest is filled in from wp/v2.
$listed = $get('/wp-json/wp/v2/session', array_filter([
    'region' => $regionId,
    'season' => $seasonId,
    'per_page' => 100,
    '_fields' => 'id,title,slug,link',
])) ?? [];

$dated = $get('/wp-json/adv-film/get-sessions')['data'] ?? [];

echo '✓ Sessions listed  (wp/v2/session, region+season): '.count($listed)."\n";
echo '✓ Sessions with dates (adv-film/get-sessions):     '.count($dated)."\n\n";

if (! $listed && ! $dated) {
    exit("Nothing readable for that region/season — check the --region and --year values.\n");
}

// ---- 4. compare against our locations --------------------------------------

// Both sides name a place then a venue; only the place is reliably shared, so
// that is what is matched on. "Sydney North NSW - Roseville" -> "sydney north".
$place = function (string $name): string {
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
    $name = preg_split('/\s[-–]\s/u', $name)[0];          // drop the venue half
    $name = preg_replace('/\b(19|20)\d{2}\b/', '', $name); // drop a trailing year
    $name = preg_replace('/\b(ACT|NSW|NT|QLD|SA|TAS|VIC|WA)\b/i', '', $name);

    return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
};

$ours = Location::where('event_id', $eventId)->orderBy('date')->get()
    ->mapWithKeys(fn ($l) => [$place($l->name) => $l]);

// Everything the site publishes, keyed on place name.
$theirs = [];

foreach ($dated as $d) {
    $f = $d['fields'] ?? [];
    $key = $place((string) ($f['location'] ?? $d['post_title'] ?? ''));
    if ($key === '') {
        continue;
    }
    $theirs[$key] = [
        'title' => $f['location'] ?? $d['post_title'],
        'date' => $f['date_time_start_utc'] ?? null,
        'venue' => $f['venue'] ?? null,
        'status' => $f['status']['label'] ?? null,
    ];
}

foreach ($listed as $sShow) {
    $key = $place($sShow['title']['rendered']);
    if ($key === '' || isset($theirs[$key])) {
        continue;   // already have it, with a date
    }
    $theirs[$key] = [
        'title' => html_entity_decode($sShow['title']['rendered']),
        'date' => null,
        'venue' => null,
        'status' => null,
    ];
}

$theirs = collect($theirs);

$both = $ours->keys()->intersect($theirs->keys());

printf("MATCHED (%d)\n", $both->count());
foreach ($both as $k) {
    $o = $ours[$k];
    $t = $theirs[$k];
    // Location.date is stored as a plain Y-m-d string and deliberately not cast.
    $ourDate = substr((string) $o->date, 0, 10);
    $note = $t['date']
        ? ('site says '.substr($t['date'], 0, 10).($ourDate !== substr($t['date'], 0, 10) ? '  <-- DATE DIFFERS' : '  (same day)'))
        : 'no date published by the site';
    printf("  %-28s app %s  |  %s\n", $k, $ourDate ?: '?', $note);
}

$onlyTheirs = $theirs->keys()->diff($ours->keys());
printf("\nON THE SITE, NOT IN THE WIN APP (%d)\n", $onlyTheirs->count());
foreach ($onlyTheirs as $k) {
    $t = $theirs[$k];
    printf("  %-28s %-34s %s %s\n", $k, $t['title'], substr((string) $t['date'], 0, 10) ?: '(no date)', $t['venue'] ? '@ '.$t['venue'] : '');
}

$onlyOurs = $ours->keys()->diff($theirs->keys());
printf("\nIN THE WIN APP, NOT ON THE SITE (%d)\n", $onlyOurs->count());
foreach ($onlyOurs as $k) {
    printf("  %-28s %s  (%s)\n", $k, $ours[$k]->name, substr((string) $ours[$k]->date, 0, 10));
}

echo "\nRead-only. Nothing was written to the film site or to our database.\n";
