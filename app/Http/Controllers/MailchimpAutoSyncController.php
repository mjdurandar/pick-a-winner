<?php

namespace App\Http\Controllers;

use App\Services\AutoMailchimpService;
use App\Services\MailchimpService;
use Illuminate\Http\Request;

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

            // Get lists for the selected account or default to ANZ
            $selectedAccount = $settings['mailchimp_account'] ?? 'anz';
            $lists = [];

            try {
                $mailchimpService = new \App\Services\MailchimpService($selectedAccount);
                $lists = $mailchimpService->getLists();
            } catch (\Exception $e) {
                // If the selected account is not configured, try to get lists from any configured account
                foreach ($availableAccounts as $accountKey => $account) {
                    if ($account['enabled']) {
                        try {
                            $mailchimpService = new \App\Services\MailchimpService($accountKey);
                            $lists = $mailchimpService->getLists();
                            $settings['mailchimp_account'] = $accountKey; // Update to a working account
                            break;
                        } catch (\Exception $ex) {
                            continue;
                        }
                    }
                }
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
            'auto_sync' => 'required|boolean',
            'default_list_id' => 'required_if:auto_sync,true|string',
            'default_tags' => 'array',
            'enabled_locations' => 'array',
            'film_tour' => 'required|string',
            'mailchimp_account' => 'required|string|in:anz,usa',
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
