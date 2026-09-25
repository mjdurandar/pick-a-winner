<?php

namespace App\Http\Controllers;

use App\Services\AutoMailchimpService;
use App\Services\MailchimpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MailchimpAutoSyncController extends Controller
{
    protected $autoMailchimpService;

    protected $mailchimpService;

    public function __construct(AutoMailchimpService $autoMailchimpService, MailchimpService $mailchimpService)
    {
        $this->autoMailchimpService = $autoMailchimpService;
        $this->mailchimpService = $mailchimpService;
    }

    public function getSettings(Request $request)
    {
        $eventId = $request->input('event_id');
        if (! $eventId) {
            return response()->json(['error' => 'Event ID is required'], 400);
        }

        try {
            $settings = $this->autoMailchimpService->getSettings($eventId);
            $availableAccounts = \App\Services\MailchimpService::getAvailableAccounts();

            // The account follows the event's country, so there is no falling back to
            // another one: a USA event's contacts belong in the USA account or
            // nowhere. If it is not configured the audience simply cannot be read,
            // and the screen says so rather than quietly retargeting the sync.
            $selectedAccount = $settings['mailchimp_account'];
            $lists = [];

            try {
                $mailchimpService = new \App\Services\MailchimpService($selectedAccount);
                $lists = $mailchimpService->getLists();
            } catch (\Exception $e) {
                Log::warning('Could not read audiences for the auto-sync account', [
                    'event_id' => $eventId,
                    'account' => $selectedAccount,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'settings' => $settings,
                'available_lists' => $lists,
                'available_accounts' => $availableAccounts,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            // Auto-sync and the audience are forced by AutoMailchimpService — always
            // on, always the account's WIN APP list — so whatever arrives is ignored.
            'auto_sync' => 'sometimes|boolean',
            'default_list_id' => 'sometimes|nullable|string',
            'default_tags' => 'array',
            'enabled_locations' => 'array',
            'film_tour' => 'required|string',
            // The account follows the event's country, so this too is ignored.
            'mailchimp_account' => 'sometimes|string|in:anz,usa',
            'event_id' => 'required|integer',
            'interest_tag_map' => 'nullable|array',
            'interest_tag_map.*' => 'nullable|string',
        ]);

        try {
            $settings = $this->autoMailchimpService->updateSettings($request->all());

            return response()->json([
                'settings' => $settings,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getListsForAccount(Request $request)
    {
        $account = $request->input('account', 'anz');

        try {
            $mailchimpService = new \App\Services\MailchimpService($account);
            $lists = $mailchimpService->getLists();

            return response()->json(['lists' => $lists]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
