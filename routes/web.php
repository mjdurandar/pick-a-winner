<?php

use App\Http\Controllers\AttendeesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\ExportLogsController;
use App\Http\Controllers\FilmsController;
use App\Http\Controllers\GoogleIntegrationController;
use App\Http\Controllers\LaravelLogsController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MailchimpAutoSyncController;
use App\Http\Controllers\MailchimpImportController;
use App\Http\Controllers\MasterSheetController;
use App\Http\Controllers\McDashboardController;
use App\Http\Controllers\NewsletterResubscribeReportController;
use App\Http\Controllers\PickaWinnerController;
use App\Http\Controllers\PrizeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SheetSyncController;
use App\Http\Controllers\SignUpFormController;
use App\Http\Controllers\UsersController;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public Pick a Winner Routes
Route::get('/pickawinner', [PickaWinnerController::class, 'index'])->name('pickawinner.index');
Route::get('/pickawinner/{eventId}', [PickaWinnerController::class, 'pickawinner'])->name('pickawinner.page');
Route::get('/pickawinner/show/{location}/{event}', [PickaWinnerController::class, 'pickawinnerlocationpage'])->name('pickawinner.locationpage');
Route::get('/pickawinner/alllocation/{event}', [PickaWinnerController::class, 'alllocation'])->name('pickawinner.alllocation');
// Shareable host instruction guide (uuid-gated public link). Shows how to run a
// draw and lists the per-location passwords for hosts.
Route::get('/host-guide/{event_uuid}', [PickaWinnerController::class, 'hostGuide'])->name('pickawinner.hostguide');
Route::post('/host-guide/{event_uuid}/verify', [PickaWinnerController::class, 'verifyHostGuide'])
    ->middleware('throttle:10,1')
    ->name('pickawinner.hostguide.verify');
Route::get('/api/events/{event}/locations', [PickaWinnerController::class, 'getLocations']);
Route::post('/picka-winner/verify', [PickaWinnerController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('picka-winner.verify');

// Prize Management Routes — gated to a verified Pick-a-Winner session (the host
// must have unlocked the draw with the correct password) or an authenticated
// admin/host. See EnsurePickAWinnerAccess.
Route::middleware('pickawinner.access')->group(function () {
    Route::patch('/prize/{prize}', [PrizeController::class, 'update'])->name('prize.update');
    Route::post('/prize', [PrizeController::class, 'addPrize'])->name('prize.store');
    Route::post('/prize/alllocation', [PrizeController::class, 'addPrizeAllLocation'])->name('prize.storeAllLocation');
    Route::post('/prize/multiple', [PrizeController::class, 'storeMultiple'])->name('prize.storeMultiple');
    Route::delete('/prize/{prize}', [PrizeController::class, 'destroy'])->name('prize.destroy');
    Route::post('/prize/delete', [PrizeController::class, 'destroyQueued'])->name('prize.destroyQueued');
    Route::post('/prize/winner/{prize}', [PrizeController::class, 'addWinner'])->name('prize.assignWinner');
});

Route::get('/', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::get('/form/{event_uuid}', [SignUpFormController::class, 'embed'])->name('signup.embed');
Route::post('/form/{event_uuid}', [SignUpFormController::class, 'storeEmbeddedData'])->name('signup.storeEmbedded');
Route::post('/form/{event_uuid}/check-subscription', [SignUpFormController::class, 'checkSubscription'])->name('signup.checkSubscription');

// SHARED ROUTES
Route::middleware(['auth', RoleMiddleware::class.':admin,host'])->group(function () {
    // events page
    Route::get('/events', [EventsController::class, 'index'])->name('events.index');

    // films page
    Route::get('/films', [FilmsController::class, 'index'])->name('films.index');
    Route::get('/films/{film}/report', [FilmsController::class, 'report'])->name('films.report');

    // master sheet page
    Route::get('/master-sheet', [MasterSheetController::class, 'index'])->name('mastersheet.index');
    Route::patch('/master-sheet/{location}', [MasterSheetController::class, 'update'])->name('mastersheet.update');

    // LARAVEL LOGS (view / clear app log)
    Route::get('/laravel-logs', [LaravelLogsController::class, 'index'])->name('laravelLogs.index');
    Route::post('/laravel-logs/clear', [LaravelLogsController::class, 'clear'])->name('laravelLogs.clear');

    // MC DASHBOARD
    Route::get('/mc-dashboard', [McDashboardController::class, 'index'])->name('mcDashboard.index');
    Route::post('/mc-dashboard/snapshot', [McDashboardController::class, 'snapshotNow'])->name('mcDashboard.snapshotNow');
    Route::delete('/mc-dashboard/{snapshot}', [McDashboardController::class, 'destroy'])->name('mcDashboard.destroy');

    // ATTENDEES ROUTES
    Route::get('/attendees/{eventId}', [AttendeesController::class, 'index'])->name('attendees.index');
    Route::get('/attendees/{eventId}/export-all', [AttendeesController::class, 'exportAll'])->middleware('log.exports')->name('attendees.exportAll');
    Route::get('/attendees/{eventId}/export-csv', [AttendeesController::class, 'exportCsv'])->middleware('log.exports')->name('attendees.exportCsv');
    Route::get('/attendees/{eventId}/export-tickets', [AttendeesController::class, 'exportTickets'])->middleware('log.exports')->name('attendees.exportTickets');
    Route::get('/attendees/{eventId}/export-all-win-tix', [AttendeesController::class, 'exportAllWinTix'])->middleware('log.exports')->name('attendees.exportAllWinTix');
    Route::get('/attendees/{eventId}/location/{locationId}', [AttendeesController::class, 'locationAttendees'])->name('attendees.location');
    Route::put('/attendees/{id}', [AttendeesController::class, 'update'])->name('attendees.update');
    Route::delete('/attendee/{attendee}/event/{event}', [AttendeesController::class, 'destroy'])->name('attendees.destroy');
    Route::delete('/attendee/{attendee}/event/{event}/location/{location}', [AttendeesController::class, 'destroyFromLocation'])->name('attendees.destroyFromLocation');

    // SIGN UP FORM ROUTES
    Route::get('/signup-form/index/{eventId}', [SignUpFormController::class, 'index'])->name('signup.index');

    // LOCATION ROUTES
    Route::get('/location', [LocationController::class, 'index'])->name('location.index');
    Route::get('/location/{eventId}', [LocationController::class, 'locationpage'])->name('location.locationpage');
    Route::put('/location/{location}/password', [LocationController::class, 'updatePassword'])->name('location.updatePassword');
    Route::put('/location/update-all-passwords/{event}', [LocationController::class, 'updateAllPasswords'])->name('location.updateAllPasswords');
    Route::put('/event/{event}/password', [EventsController::class, 'updatePassword'])->name('event.updatePassword');
    Route::post('/location', [LocationController::class, 'store'])->name('location.store');
    Route::put('/location/{location}', [LocationController::class, 'update'])->name('location.update');
    Route::delete('/location/{location}', [LocationController::class, 'destroy'])->name('location.destroy');
    Route::delete('/location/delete-all/{eventId}', [LocationController::class, 'deleteAllLocations'])->name('location.deleteAll');

    // MAILCHIMP LOOKUP ROUTES (audience + merge-field pickers for auto-sync settings)
    Route::get('/api/location/mailchimp/lists', [LocationController::class, 'getMailchimpLists'])->name('location.mailchimpLists');
    Route::get('/api/location/mailchimp/merge-fields', [LocationController::class, 'getMailchimpMergeFields'])->name('location.mailchimpMergeFields');
    Route::get('/api/location/subscribers', [LocationController::class, 'getSubscribers'])->name('location.getSubscribers');

    Route::get('/location/{locationId}/ticket-attendees', [LocationController::class, 'getTicketAttendees'])->name('location.getTicketAttendees');

    // TICKET REPORTING ROUTES
    Route::get('/location/{locationId}/ticket-report', [LocationController::class, 'getLocationTicketReport'])->name('location.ticketReport');
    Route::get('/film/{filmId}/ticket-report', [LocationController::class, 'getFilmTicketReport'])->name('film.ticketReport');
    Route::get('/event/{eventId}/ticket-report', [LocationController::class, 'getEventTicketReport'])->name('event.ticketReport');

    // EXPORT ROUTES
    Route::get('/location/{locationId}/export', [LocationController::class, 'exportLocationData'])->middleware('log.exports')->name('location.export');
    Route::get('/event/{eventId}/export-all', [LocationController::class, 'exportEventData'])->middleware('log.exports')->name('event.exportAll');

    // SHEETS DATA ROUTES
    Route::get('/location/sheets-data/{eventId}', [LocationController::class, 'getSheetsData'])->name('location.getSheetsData');
    Route::post('/location/save-sheets-data', [LocationController::class, 'saveSheetsData'])->name('location.saveSheetsData');
});

Route::middleware(['auth', RoleMiddleware::class.':admin'])->group(function () {
    // MASTER SHEET SYNC — admin *and* the named owner account. See
    // EnsureMasterSheetAccess: this screen holds the Google connection and can
    // delete locations, so it is not open to every admin.
    Route::middleware(\App\Http\Middleware\EnsureMasterSheetAccess::class)->group(function () {
        // GOOGLE ACCOUNT (master sheet sync reads the shared spreadsheet as this account)
        Route::get('/integrations/google/connect', [GoogleIntegrationController::class, 'redirect'])->name('google.integration.connect');
        Route::get('/integrations/google/callback', [GoogleIntegrationController::class, 'callback'])->name('google.integration.callback');
        Route::delete('/integrations/google', [GoogleIntegrationController::class, 'destroy'])->name('google.integration.destroy');

        // MASTER SHEET SYNC (read-only: which Google tabs feed which events)
        Route::get('/master-sheet/sync', [SheetSyncController::class, 'index'])->name('sheetSync.index');
        Route::post('/master-sheet/sync/tabs', [SheetSyncController::class, 'tabs'])->name('sheetSync.tabs');
        Route::post('/master-sheet/sync/sources', [SheetSyncController::class, 'store'])->name('sheetSync.store');
        Route::patch('/master-sheet/sync/sources/{sheetSource}', [SheetSyncController::class, 'update'])->name('sheetSync.update');
        Route::delete('/master-sheet/sync/sources/{sheetSource}', [SheetSyncController::class, 'destroy'])->name('sheetSync.destroy');
        Route::post('/master-sheet/sync/sources/{sheetSource}/run', [SheetSyncController::class, 'run'])->name('sheetSync.run');
        Route::get('/master-sheet/sync/sources/{sheetSource}/changes', [SheetSyncController::class, 'changes'])->name('sheetSync.changes');
        Route::post('/master-sheet/sync/sources/{sheetSource}/approve', [SheetSyncController::class, 'approve'])->name('sheetSync.approve');
        Route::post('/master-sheet/sync/sources/{sheetSource}/discard', [SheetSyncController::class, 'discard'])->name('sheetSync.discard');
        Route::delete('/master-sheet/sync/sources/{sheetSource}/missing/{sheetSourceChange}', [SheetSyncController::class, 'removeLocation'])->name('sheetSync.removeLocation');
        Route::post('/master-sheet/sync/sources/{sheetSource}/missing/{sheetSourceChange}/keep', [SheetSyncController::class, 'keepLocation'])->name('sheetSync.keepLocation');
    });

    // EXPORT AUDIT LOGS
    Route::get('/admin/export-logs', [ExportLogsController::class, 'index'])->name('admin.exportLogs.index');
    Route::get('/admin/export-logs/export', [ExportLogsController::class, 'export'])->name('admin.exportLogs.export');

    // DASHBOARD
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/{event}', [DashboardController::class, 'filter'])->name('dashboard.filter');

    // EVENTS ROUTES
    Route::post('/events', [EventsController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [EventsController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventsController::class, 'destroy'])->name('events.destroy');

    // SIGN UP FORM ROUTES
    Route::post('/signup-form/{eventId}', [SignUpFormController::class, 'store'])->name('signup.store');
    Route::get('/signup-form/create/{eventId}', [SignUpFormController::class, 'create'])->name('signup.create');
    Route::post('/signup-form/generate/{eventId}', [SignUpFormController::class, 'generate'])->name('signup.generate');

    Route::delete('/signup-form/{eventId}', [SignUpFormController::class, 'destroy'])->name('signup.destroy');

    // EDIT SIGN UP FORM ROUTES
    Route::get('/signup-form/edit/{formId}', [SignUpFormController::class, 'edit'])->name('signup.edit');
    // UPDATE SIGN UP FORM ROUTES
    Route::post('/signup-form/update/{eventId}', [SignUpFormController::class, 'update'])->name('signup.update');

    // USERS ROUTES
    Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    Route::patch('/users/{userId}', [UsersController::class, 'update'])->name('users.update');
    Route::delete('/users/{userId}', [UsersController::class, 'destroy'])->name('users.destroy');

    // FILMS ROUTES
    Route::post('/films', [FilmsController::class, 'store'])->name('films.store');
    Route::patch('/films/{film}', [FilmsController::class, 'update'])->name('films.update');
    Route::delete('/films/{film}', [FilmsController::class, 'destroy'])->name('films.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Authenticated reporting / Mailchimp settings routes (admin + host).
Route::middleware(['auth', RoleMiddleware::class.':admin,host'])->group(function () {
    // Mailchimp Auto-sync Settings Routes
    Route::get('/mailchimp/autosync/settings', [MailchimpAutoSyncController::class, 'getSettings'])->name('mailchimp.autosync.settings');
    Route::post('/mailchimp/autosync/update', [MailchimpAutoSyncController::class, 'updateSettings'])->name('mailchimp.autosync.update');
    Route::get('/mailchimp/autosync/lists', [MailchimpAutoSyncController::class, 'getListsForAccount'])->name('mailchimp.autosync.lists');

    // Weekly Report Routes
    Route::get('/weekly-report', [App\Http\Controllers\WeeklyReportController::class, 'index'])->name('weekly-report');
    Route::match(['get', 'post'], '/weekly-report/generate', [App\Http\Controllers\WeeklyReportController::class, 'generate'])->name('weekly-report.generate');
    Route::post('/weekly-report/export', [App\Http\Controllers\WeeklyReportController::class, 'export'])->middleware('log.exports')->name('weekly-report.export');
    Route::get('/weekly-report/export-demographics-spreadsheet', [App\Http\Controllers\WeeklyReportController::class, 'exportDemographicsSpreadsheet'])->middleware('log.exports')->name('weekly-report.export-demographics-spreadsheet');
    Route::get('/weekly-report/demographics-table', [App\Http\Controllers\WeeklyReportController::class, 'getDemographicsTableData'])->name('weekly-report.demographics-table');
    Route::post('/weekly-report/export-pdf', [App\Http\Controllers\WeeklyReportController::class, 'exportPdf'])->middleware('log.exports')->name('weekly-report.export-pdf');
    Route::get('/weekly-report/event-breakdown', [App\Http\Controllers\WeeklyReportController::class, 'getEventBreakdown'])->name('weekly-report.event-breakdown');
    Route::post('/weekly-report/event-breakdown/export-pdf', [App\Http\Controllers\WeeklyReportController::class, 'exportEventBreakdownPdf'])->middleware('log.exports')->name('weekly-report.event-breakdown.export-pdf');
    Route::post('/weekly-report/end-of-film-tour/export-pdf', [App\Http\Controllers\WeeklyReportController::class, 'exportEndOfFilmTourPdf'])->middleware('log.exports')->name('weekly-report.end-of-film-tour.export-pdf');
});

// Mailchimp CSV import wizard. Admin only. The upload endpoint is rate limited
// because it accepts a 10 MB body and parses it.
Route::middleware(['auth', RoleMiddleware::class.':admin'])->prefix('mailchimp-import')->group(function () {
    Route::get('/', [MailchimpImportController::class, 'index'])->name('mailchimpImport.index');
    Route::get('/audiences', [MailchimpImportController::class, 'audiences'])->name('mailchimpImport.audiences');
    Route::post('/start', [MailchimpImportController::class, 'start'])->name('mailchimpImport.start');
    Route::post('/{import}/upload', [MailchimpImportController::class, 'upload'])
        ->middleware('throttle:20,1')
        ->name('mailchimpImport.upload');
    Route::post('/{import}/configure', [MailchimpImportController::class, 'configure'])->name('mailchimpImport.configure');
    Route::post('/{import}/dry-run', [MailchimpImportController::class, 'dryRun'])->name('mailchimpImport.dryRun');
    Route::get('/{import}/preview', [MailchimpImportController::class, 'preview'])->name('mailchimpImport.preview');
    Route::post('/{import}/run', [MailchimpImportController::class, 'run'])->name('mailchimpImport.run');
    Route::get('/{import}/download', [MailchimpImportController::class, 'download'])
        ->middleware('log.exports')
        ->name('mailchimpImport.download');
    // The compliance-blocked contacts with the consent behind them, for a restore
    // request to Mailchimp Support.
    Route::get('/{import}/consent-evidence', [MailchimpImportController::class, 'consentEvidence'])
        ->middleware('log.exports')
        ->name('mailchimpImport.consentEvidence');
    // Cancels it if it is running, and takes its remaining contacts out of the
    // signup form's queue either way.
    Route::delete('/{import}', [MailchimpImportController::class, 'destroy'])->name('mailchimpImport.destroy');
});

// Newsletter resubscribes made by the sign-up form, per film. Separate from the CSV
// import wizard above — these are recovered contacts, not uploaded ones.
Route::middleware(['auth', RoleMiddleware::class.':admin'])->group(function () {
    Route::get('/newsletter-resubscribes/download', [NewsletterResubscribeReportController::class, 'download'])
        ->middleware('log.exports')
        ->name('newsletterResubscribes.download');

    // Declared after /download so the literal segment wins; whereNumber keeps the
    // two from overlapping either way.
    Route::get('/newsletter-resubscribes/{event}', [NewsletterResubscribeReportController::class, 'index'])
        ->whereNumber('event')
        ->name('newsletterResubscribes.index');
});

// API Routes
Route::prefix('api')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/location/mailchimp/lists', [LocationController::class, 'getMailchimpLists'])->name('location.mailchimpLists');
    Route::get('/location/subscribers', [LocationController::class, 'getSubscribers'])->name('location.getSubscribers');
});

require __DIR__.'/auth.php';
