<?php

namespace App\Services;

use App\Models\Events;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoMailchimpService
{
    protected $mailchimpService;

    protected $logService;

    protected $settingsKey = 'mailchimp_autosync_settings_';

    public function __construct(MailchimpService $mailchimpService, MailchimpLogService $logService)
    {
        $this->mailchimpService = $mailchimpService;
        $this->logService = $logService;
    }

    /**
     * The WIN APP audience for a Mailchimp account. Auto-sync always writes to this
     * audience, so it is read from config rather than chosen per event.
     */
    public static function winAppListId(?string $account): string
    {
        $account = in_array($account, ['anz', 'usa'], true) ? $account : 'anz';

        return (string) config("services.mailchimp.win_app_list.{$account}", '');
    }

    /**
     * Which of the two Mailchimp accounts a country belongs to. North America is the
     * USA account, everything else (Australia, New Zealand) is ANZ. Matches the
     * country strings both events ("USA & CANADA") and locations ("USA", "Canada")
     * are stored with.
     */
    public static function accountForCountry(?string $country): string
    {
        $c = strtoupper(trim((string) $country));

        return (str_contains($c, 'USA') || str_contains($c, 'CANADA')) ? 'usa' : 'anz';
    }

    /**
     * The account an event syncs through, taken from the event's own country and
     * falling back to its locations' when the event has none. Never a stored choice:
     * a USA event must not post its contacts into the ANZ audience.
     */
    public static function accountForEvent($eventId): string
    {
        $event = Events::find($eventId);
        $country = $event->event_country ?? null;

        if (trim((string) $country) === '') {
            $country = Location::where('event_id', $eventId)
                ->whereNotNull('country')
                ->value('country');
        }

        return self::accountForCountry($country);
    }

    /**
     * Auto-sync is compulsory: every event syncs, always through the account its
     * country belongs to, and always to that account's WIN APP audience. Nobody
     * configures any of the three, so all are forced here — on read as well as on
     * write, so events whose settings were saved before this (auto-sync off, wrong
     * account, or another audience) are corrected too.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function applyForcedSettings(array $settings): array
    {
        $settings['mailchimp_account'] = self::accountForEvent($settings['event_id'] ?? null);
        $settings['auto_sync'] = true;
        $settings['default_list_id'] = self::winAppListId($settings['mailchimp_account']);

        return $settings;
    }

    public function getSettings($eventId)
    {
        $settings = Cache::get($this->settingsKey.$eventId, [
            'auto_sync' => true,
            'default_list_id' => '',
            'default_tags' => [],
            'enabled_locations' => [],
            'film_tour' => 'WM',
            'mailchimp_account' => 'anz',
            'interest_tag_map' => [],
            'event_id' => $eventId,
        ]);

        if (! array_key_exists('interest_tag_map', $settings) || ! is_array($settings['interest_tag_map'])) {
            $settings['interest_tag_map'] = [];
        }

        $settings['event_id'] = $eventId;

        return $this->applyForcedSettings($settings);
    }

    public function updateSettings($settings)
    {
        $eventId = $settings['event_id'];
        $settings = $this->applyForcedSettings($settings);
        Cache::put($this->settingsKey.$eventId, $settings);

        return $settings;
    }

    /**
     * Build the SHOW / SOURCE / COUNTRY tag set for a location, matching the manual
     * "Import All" formula (LocationPage.vue). Used by the scheduled auto-import so
     * automated runs tag contacts identically to manual imports.
     *
     * @param  string  $mode  'ticket' => SOURCE ... TIX <year>, 'form' => SOURCE ... COMP <year>
     * @return array<int, string>
     */
    public function buildLocationTags(Location $location, string $mode, ?array $settings = null): array
    {
        $settings = $settings ?? $this->getSettings($location->event_id);

        $filmTour = $settings['film_tour'] ?? 'WM';
        $defaultTags = is_array($settings['default_tags'] ?? null) ? $settings['default_tags'] : [];
        $sourceSuffix = $mode === 'ticket' ? 'TIX' : 'COMP';

        $event = $location->event;
        $year = $event && $event->event_year ? $event->event_year : date('Y');
        // Country used for USA-format detection matches the frontend: event country, falling back to the location's.
        $country = $event && $event->event_country ? $event->event_country : ($location->country ?? '');
        $state = trim((string) ($location->state ?? ''));

        $showLoc = $this->locationTagForShow($location->name, $state, $country);
        $sourceLoc = $this->locationTagForSource($location->name, $state);

        $tags = [
            'SHOW - '.$showLoc,
            'SOURCE - '.strtoupper($filmTour).' '.$sourceLoc.' '.$sourceSuffix.' '.$year,
        ];

        $countryTag = trim((string) ($location->country ?? ''));
        if ($countryTag !== '') {
            $tags[] = 'COUNTRY - '.strtoupper($countryTag);
        }

        return array_values(array_filter(array_merge($tags, $defaultTags), fn ($t) => is_string($t) && trim($t) !== ''));
    }

    /**
     * SOURCE-tag location label. Strips the location's state (or a trailing 2-3 letter
     * abbreviation like VIC/NSW) so the state is never part of the tag. Mirrors
     * locationTagForSource() in LocationPage.vue.
     */
    private function locationTagForSource(?string $name, string $state): string
    {
        $part = trim(explode(' - ', (string) $name)[0]);
        if ($part === '') {
            return 'LOC';
        }
        if ($state !== '') {
            $out = preg_replace('/,?\s*'.preg_quote($state, '/').'$/i', '', $part);
        } else {
            $out = preg_replace('/,?\s+[A-Z]{2,3}$/i', '', $part);
        }
        $out = $out === null ? $part : trim($out);

        return strtoupper($out !== '' ? $out : $part);
    }

    /**
     * SHOW-tag location label. USA keeps the state ("DENVER, CO"); Australia/NZ/other
     * strip the trailing state abbreviation ("FALLS CREEK VIC" -> "FALLS CREEK").
     * Mirrors locationTagForShow() in LocationPage.vue.
     */
    private function locationTagForShow(?string $name, string $state, ?string $country): string
    {
        $part = trim(explode(' - ', (string) $name)[0]);
        if ($part === '') {
            return 'LOC';
        }
        $c = strtoupper(trim((string) $country));
        $isUSA = in_array($c, ['USA', 'USA & CANADA', 'USA AND CANADA'], true);

        if ($isUSA && $state !== '') {
            $base = preg_replace('/,?\s+[A-Z]{2,3}$/i', '', $part);
            $base = $base === null ? $part : trim($base);

            return strtoupper($base.', '.$state);
        }
        if ($isUSA) {
            return strtoupper($part);
        }

        // Non-USA: strip a trailing 2-3 letter abbreviation, then the full state name if given.
        $out = preg_replace('/,?\s+[A-Z]{2,3}$/i', '', $part);
        $out = $out === null || trim($out) === '' ? $part : trim($out);
        if ($state !== '') {
            $stripped = preg_replace('/,?\s*'.preg_quote($state, '/').'$/i', '', $out);
            if ($stripped !== null && trim($stripped) !== '') {
                $out = trim($stripped);
            }
        }

        return strtoupper($out !== '' ? $out : $part);
    }

    /**
     * @param  bool  $rethrow  Re-throw after logging, so a queued caller can retry.
     *                         Left false for syncSubscribers(), where one bad row
     *                         must not stop the rest of the batch.
     */
    public function syncSubscriber($subscriber, $locationId, bool $rethrow = false)
    {
        try {
            $location = Location::with('event')->find($locationId);
            if (! $location) {
                Log::error('Location not found', ['location_id' => $locationId]);

                return;
            }

            $settings = $this->getSettings($location->event_id);

            // Generate location-specific tags regardless of auto-sync status
            $locationName = $location->name;
            $filmTour = $settings['film_tour'];
            $year = date('Y');

            // Extract everything before hyphen for both SHOW and SOURCE tags
            $locationTag = explode(' - ', $locationName)[0];
            $locationTagUpper = strtoupper($locationTag);

            // Process location tags for USA/CANADA events
            $event = $location->event;
            $eventCountry = strtoupper($event->event_country ?? '');
            $isUsaOrCanada = in_array($eventCountry, ['USA', 'CANADA', 'USA & CANADA']);

            // Extract state from location tag (last 2-3 letter word) for USA/CANADA
            $locationParts = explode(' ', trim($locationTagUpper));
            $state = '';
            $locationWithoutState = $locationTagUpper;

            if ($isUsaOrCanada && count($locationParts) > 1) {
                $lastPart = end($locationParts);
                // Check if last part is a state code (2-3 uppercase letters)
                if (preg_match('/^[A-Z]{2,3}$/', $lastPart)) {
                    $state = $lastPart;
                    $locationWithoutState = trim(str_replace($state, '', $locationTagUpper));
                }
            }

            // Format tags
            $showTagLocation = $isUsaOrCanada && $state
                ? trim($locationWithoutState).', '.$state
                : $locationTagUpper;
            $sourceTagLocation = $isUsaOrCanada && $state
                ? trim($locationWithoutState)
                : $locationTagUpper;

            $sourceTag = 'SOURCE - '.strtoupper($filmTour).' '.$sourceTagLocation.' COMP '.$year;
            $showTag = 'SHOW - '.$showTagLocation;

            // Combine with default tags
            $tags = array_merge(
                [$sourceTag, $showTag],
                is_array($settings['default_tags']) ? $settings['default_tags'] : []
            );

            $interestTags = MailchimpService::mapFaveSportToInterestTags(
                $subscriber->fave_sport ?? null,
                $settings['interest_tag_map'] ?? []
            );
            if (! empty($interestTags)) {
                $tags = array_merge($tags, $interestTags);
            }

            // Check if auto-sync is enabled and has default list
            if (! $settings['auto_sync'] || ! $settings['default_list_id']) {
                Log::info('Auto-sync disabled or no default list configured', [
                    'event_id' => $location->event_id,
                    'auto_sync' => $settings['auto_sync'],
                    'default_list_id' => $settings['default_list_id'],
                ]);

                // Still log the attempt even if auto-sync is disabled
                $this->logService->logImport($locationId, $locationName, [
                    'success' => false,
                    'email' => $subscriber->email_address ?? 'no email',
                    'error' => 'Auto-sync is disabled or no default list configured',
                    'tags' => $tags,
                ]);

                return;
            }

            // Create MailchimpService instance with selected account
            $selectedAccount = $settings['mailchimp_account'] ?? 'anz';
            $mailchimpService = new MailchimpService($selectedAccount);

            // If this contact was archived in Mailchimp, unarchive them before the upsert.
            // addSubscriberToList() uses status_if_new which Mailchimp ignores for existing
            // members, so archived contacts would otherwise stay archived even after re-signup.
            try {
                $statusCheck = $mailchimpService->getSubscriberStatus(
                    $settings['default_list_id'],
                    $subscriber->email_address
                );
                if (($statusCheck['exists'] ?? false) && ($statusCheck['status'] ?? null) === 'archived') {
                    Log::info('Auto-sync: subscriber archived in Mailchimp, unarchiving before upsert', [
                        'email' => $subscriber->email_address,
                        'location' => $locationName,
                    ]);
                    $mailchimpService->resubscribe(
                        $settings['default_list_id'],
                        $subscriber->email_address
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Auto-sync: archive status check failed, continuing with upsert', [
                    'email' => $subscriber->email_address,
                    'error' => $e->getMessage(),
                ]);
            }

            // Add to Mailchimp
            $mailchimpService->addSubscriberToList(
                $settings['default_list_id'],
                [
                    'email_address' => $subscriber->email_address,
                    'first_name' => $subscriber->first_name ?? '',
                    'last_name' => $subscriber->last_name ?? '',
                    'mobile_number' => $subscriber->mobile_number ?? '',
                    'street_address' => $subscriber->street_address ?? '',
                    'street_address_2' => $subscriber->street_address_2 ?? '',
                    'city' => $subscriber->city ?? '',
                    'state' => $subscriber->state ?? '',
                    'zip_code' => $subscriber->zip_code ?? '',
                    'country' => $subscriber->country ?? '',
                    'gender' => $subscriber->gender ?? '',
                    'age' => $subscriber->age ?? '',
                ],
                $tags
            );

            // Log successful import
            Log::info('About to log successful import', [
                'locationId' => $locationId,
                'locationName' => $locationName,
                'email' => $subscriber->email_address ?? 'no email',
                'tags' => $tags,
            ]);

            $this->logService->logImport($locationId, $locationName, [
                'success' => true,
                'email' => $subscriber->email_address ?? 'no email',
                'tags' => $tags,
            ]);

            Log::info('Subscriber auto-synced to Mailchimp', [
                'email' => $subscriber->email_address,
                'location' => $locationName,
                'tags' => $tags,
            ]);

        } catch (\Exception $e) {
            // Log failed import
            Log::error('About to log failed import', [
                'locationId' => $locationId,
                'locationName' => $locationName ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            $this->logService->logImport($locationId, $locationName ?? 'unknown', [
                'success' => false,
                'email' => $subscriber->email_address ?? 'no email',
                'error' => $e->getMessage(),
                'tags' => $tags ?? [],
            ]);

            Log::error('Failed to auto-sync subscriber', [
                'error' => $e->getMessage(),
                'location_id' => $locationId,
                'subscriber' => $subscriber->email_address ?? 'unknown',
            ]);

            if ($rethrow) {
                throw $e;
            }
        }
    }

    public function syncSubscribers($subscribers, $locationId)
    {
        foreach ($subscribers as $subscriber) {
            $this->syncSubscriber($subscriber, $locationId);
        }
    }
}
