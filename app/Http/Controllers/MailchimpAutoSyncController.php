<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Services\AutoMailchimpService;
use App\Services\MailchimpService;

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
        if (!$eventId) {
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

            // Durable per-event auto-import config (scheduled "import finished locations").
            $event = Events::find($eventId);
            $autoImport = [
                'enabled' => (bool) ($event->auto_import_enabled ?? false),
                'list_id' => $event->auto_import_list_id ?? '',
                'list_name' => $event->auto_import_list_name ?? '',
                'account' => $event->auto_import_account ?? ($settings['mailchimp_account'] ?? 'anz'),
            ];

            return response()->json([
                'settings' => $settings,
                'available_lists' => $lists,
                'available_accounts' => $availableAccounts,
                'auto_import' => $autoImport,
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
            'auto_import_enabled' => 'nullable|boolean',
            'auto_import_list_id' => 'required_if:auto_import_enabled,true,1|nullable|string|max:64',
            'auto_import_list_name' => 'nullable|string|max:255',
            'auto_import_account' => 'required_if:auto_import_enabled,true,1|nullable|string|in:anz,usa',
        ]);

        try {
            $settings = $this->autoMailchimpService->updateSettings($request->all());

            // Persist the durable auto-import config to the events table.
            $event = Events::find($request->input('event_id'));
            if ($event) {
                $autoEnabled = (bool) $request->input('auto_import_enabled', false);
                $event->auto_import_enabled = $autoEnabled;
                $event->auto_import_list_id = $autoEnabled ? $request->input('auto_import_list_id') : $event->auto_import_list_id;
                $event->auto_import_list_name = $autoEnabled ? $request->input('auto_import_list_name') : $event->auto_import_list_name;
                $event->auto_import_account = $autoEnabled ? $request->input('auto_import_account') : $event->auto_import_account;
                $event->save();
            }

            return response()->json([
                'settings' => $settings,
                'auto_import' => [
                    'enabled' => (bool) ($event->auto_import_enabled ?? false),
                    'list_id' => $event->auto_import_list_id ?? '',
                    'list_name' => $event->auto_import_list_name ?? '',
                    'account' => $event->auto_import_account ?? 'anz',
                ],
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