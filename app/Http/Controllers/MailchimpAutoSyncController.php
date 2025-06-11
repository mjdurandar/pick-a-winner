<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AutoMailchimpService;
use App\Services\MailchimpService;
use Inertia\Inertia;

class MailchimpAutoSyncController extends Controller
{
    protected $autoMailchimpService;
    protected $mailchimpService;

    public function __construct(AutoMailchimpService $autoMailchimpService, MailchimpService $mailchimpService)
    {
        $this->autoMailchimpService = $autoMailchimpService;
        $this->mailchimpService = $mailchimpService;
    }

    public function getSettings()
    {
        $config = $this->autoMailchimpService->getConfig();
        $lists = $this->mailchimpService->getLists();

        return response()->json([
            'settings' => $config,
            'available_lists' => $lists
        ]);
    }

    public function updateSettings(Request $request)
    {
        $settings = $request->validate([
            'auto_sync' => 'required|boolean',
            'delay_minutes' => 'required|integer|min:0',
            'default_list_id' => 'required|string',
            'default_tags' => 'array',
            'enabled_locations' => 'array',
            'film_tour' => 'string|nullable'
        ]);

        if (!isset($settings['film_tour'])) {
            $settings['film_tour'] = 'WM';
        }

        $this->autoMailchimpService->updateConfig($settings);

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $this->autoMailchimpService->getConfig()
        ]);
    }
} 