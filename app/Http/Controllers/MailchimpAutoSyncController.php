<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
            $lists = $this->mailchimpService->getLists();

            return response()->json([
                'settings' => $settings,
                'available_lists' => $lists
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'auto_sync' => 'required|boolean',
            'default_list_id' => 'required|string',
            'default_tags' => 'array',
            'enabled_locations' => 'array',
            'film_tour' => 'required|string',
            'event_id' => 'required|integer'
        ]);

        try {
            $settings = $this->autoMailchimpService->updateSettings($request->all());
            return response()->json(['settings' => $settings]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
} 