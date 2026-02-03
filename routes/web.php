<?php

use App\Http\Controllers\AttendeesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\PickaWinnerController;
use App\Http\Controllers\SignUpFormController;
use App\Http\Controllers\PrizeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LaravelLogsController;
use App\Http\Controllers\MailchimpImportLogsController;
use App\Http\Controllers\MailchimpAutoSyncController;
use App\Http\Controllers\FilmsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Middleware\RoleMiddleware;

// Public Pick a Winner Routes
Route::get('/pickawinner', [PickaWinnerController::class, 'index'])->name('pickawinner.index');
Route::get('/pickawinner/{eventId}', [PickaWinnerController::class, 'pickawinner'])->name('pickawinner.page');
Route::get('/pickawinner/show/{location}/{event}', [PickaWinnerController::class, 'pickawinnerlocationpage'])->name('pickawinner.locationpage');
Route::get('/pickawinner/alllocation/{event}', [PickaWinnerController::class, 'alllocation'])->name('pickawinner.alllocation');
Route::get('/api/events/{event}/locations', [PickaWinnerController::class, 'getLocations']);
Route::post('/picka-winner/verify', [PickaWinnerController::class, 'verify'])->name('picka-winner.verify');

// Prize Management Routes (no auth required)
Route::patch('/prize/{prize}', [PrizeController::class, 'update'])->name('prize.update');
Route::post('/prize', [PrizeController::class, 'addPrize'])->name('prize.store');
Route::post('/prize/alllocation', [PrizeController::class, 'addPrizeAllLocation'])->name('prize.storeAllLocation');
Route::post('/prize/multiple', [PrizeController::class, 'storeMultiple'])->name('prize.storeMultiple');
Route::delete('/prize/{prize}', [PrizeController::class, 'destroy'])->name('prize.destroy');
Route::post('/prize/winner/{prize}', [PrizeController::class, 'addWinner'])->name('prize.assignWinner');

Route::get('/', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::get('/form/{event_uuid}', [SignUpFormController::class, 'embed'])->name('signup.embed');
Route::post('/form/{event_uuid}', [SignUpFormController::class, 'storeEmbeddedData'])->name('signup.storeEmbedded');

//SHARED ROUTES
Route::middleware(['auth', RoleMiddleware::class . ':admin,host'])->group(function () {
    //events page
    Route::get('/events', [EventsController::class, 'index'])->name('events.index');
    
    //films page
    Route::get('/films', [FilmsController::class, 'index'])->name('films.index');

    //LARAVEL LOGS (view / clear app log)
    Route::get('/laravel-logs', [LaravelLogsController::class, 'index'])->name('laravelLogs.index');
    Route::post('/laravel-logs/clear', [LaravelLogsController::class, 'clear'])->name('laravelLogs.clear');

    //MAILCHIMP IMPORT LOGS (dedicated page + export)
    Route::get('/mailchimp-import-logs', [MailchimpImportLogsController::class, 'index'])->name('mailchimpImportLogs.index');
    Route::get('/mailchimp-import-logs/queued-imports', [MailchimpImportLogsController::class, 'queuedImports'])->name('mailchimpImportLogs.queuedImports');
    Route::post('/mailchimp-import-logs/queued-imports/cancel-location', [MailchimpImportLogsController::class, 'cancelQueuedLocation'])->name('mailchimpImportLogs.cancelQueuedLocation');
    Route::delete('/mailchimp-import-logs/queued-imports/{jobId}', [MailchimpImportLogsController::class, 'cancelQueuedImport'])->name('mailchimpImportLogs.cancelQueuedImport');
    Route::get('/mailchimp-import-logs/{id}/download', [MailchimpImportLogsController::class, 'download'])->name('mailchimpImportLogs.download');
    Route::post('/mailchimp-import-logs/reimport-failed', [MailchimpImportLogsController::class, 'reimportFailedRows'])->name('mailchimpImportLogs.reimportFailed');
    Route::delete('/mailchimp-import-logs/all', [MailchimpImportLogsController::class, 'destroyAll'])->name('mailchimpImportLogs.destroyAll');
    Route::post('/mailchimp-import-logs/destroy-multiple', [MailchimpImportLogsController::class, 'destroyMultiple'])->name('mailchimpImportLogs.destroyMultiple');
    Route::delete('/mailchimp-import-logs/{id}', [MailchimpImportLogsController::class, 'destroy'])->name('mailchimpImportLogs.destroy');

    //ATTENDEES ROUTES
    Route::get('/attendees/{eventId}', [AttendeesController::class, 'index'])->name('attendees.index');
    Route::get('/attendees/{eventId}/export-all', [AttendeesController::class, 'exportAll'])->name('attendees.exportAll');
    Route::post('/attendees/{eventId}/export-csv', [AttendeesController::class, 'exportCsv'])->name('attendees.exportCsv');
    Route::get('/attendees/{eventId}/location/{locationId}', [AttendeesController::class, 'locationAttendees'])->name('attendees.location');
    Route::put('/attendees/{id}', [AttendeesController::class, 'update'])->name('attendees.update');
    Route::delete('/attendee/{attendee}/event/{event}', [AttendeesController::class, 'destroy'])->name('attendees.destroy');
    Route::delete('/attendee/{attendee}/event/{event}/location/{location}', [AttendeesController::class, 'destroyFromLocation'])->name('attendees.destroyFromLocation');

    //SIGN UP FORM ROUTES
    Route::get('/signup-form/index/{eventId}', [SignUpFormController::class, 'index'])->name('signup.index');

    //LOCATION ROUTES
    Route::get('/location', [LocationController::class, 'index'])->name('location.index');
    Route::get('/location/{eventId}', [LocationController::class, 'locationpage'])->name('location.locationpage');
    Route::put('/location/{location}/password', [LocationController::class, 'updatePassword'])->name('location.updatePassword');
    Route::put('/location/update-all-passwords/{event}', [LocationController::class, 'updateAllPasswords'])->name('location.updateAllPasswords');
    Route::put('/event/{event}/password', [EventsController::class, 'updatePassword'])->name('event.updatePassword');
    Route::post('/location', [LocationController::class, 'store'])->name('location.store');
    Route::put('/location/{location}', [LocationController::class, 'update'])->name('location.update');
    Route::delete('/location/{location}', [LocationController::class, 'destroy'])->name('location.destroy');
    Route::delete('/location/delete-all/{eventId}', [LocationController::class, 'deleteAllLocations'])->name('location.deleteAll');

    //IMPORT DATA TO MAILCHIMP ROUTES
    Route::post('/location/import-data-to-mailchimp', [LocationController::class, 'importDataToMailChimp'])->name('location.importDataToMailChimp');
    Route::post('/location/manual-import-to-mailchimp', [LocationController::class, 'manualImportToMailchimp'])->name('location.manualImportToMailchimp');
    Route::post('/location/log-mailchimp-import', [LocationController::class, 'logMailchimpImport'])->name('location.logMailchimpImport');
    Route::post('/location/generate-spreadsheet-data', [LocationController::class, 'generateSpreadsheetData'])->name('location.generateSpreadsheetData');
    Route::get('/location/{locationId}/mailchimp-report', [LocationController::class, 'getMailchimpLocationReport'])->name('location.mailchimpReport');
    Route::get('/api/location/mailchimp/lists', [LocationController::class, 'getMailchimpLists'])->name('location.mailchimpLists');
    Route::get('/api/location/mailchimp/merge-fields', [LocationController::class, 'getMailchimpMergeFields'])->name('location.mailchimpMergeFields');
    Route::get('/api/location/subscribers', [LocationController::class, 'getSubscribers'])->name('location.getSubscribers');
    
    //EVENTBRITE ROUTES
    Route::post('/location/fetch-eventbrite-attendees', [LocationController::class, 'fetchEventbriteAttendees'])->name('location.fetchEventbriteAttendees');
    Route::post('/location/fetch-eventbrite-attendees-preview', [LocationController::class, 'fetchEventbriteAttendeesPreview'])->name('location.fetchEventbriteAttendeesPreview');
    Route::post('/location/import-eventbrite-to-mailchimp', [LocationController::class, 'importEventbriteToMailchimp'])->name('location.importEventbriteToMailchimp');
    Route::get('/event/{eventId}/mailchimp-import-preview', [LocationController::class, 'getEventMailchimpImportPreview'])->name('event.mailchimpImportPreview');
    Route::post('/event/import-all', [LocationController::class, 'eventImportAll'])->name('event.importAll');

    //MANUAL CSV IMPORT (ticket attendees)
    Route::post('/location/import-csv-ticket-attendees', [LocationController::class, 'importCsvTicketAttendees'])->name('location.importCsvTicketAttendees');
    Route::get('/location/{locationId}/ticket-attendees', [LocationController::class, 'getTicketAttendees'])->name('location.getTicketAttendees');
    
    //TICKET REPORTING ROUTES
    Route::get('/location/{locationId}/ticket-report', [LocationController::class, 'getLocationTicketReport'])->name('location.ticketReport');
    Route::get('/film/{filmId}/ticket-report', [LocationController::class, 'getFilmTicketReport'])->name('film.ticketReport');
    Route::get('/event/{eventId}/ticket-report', [LocationController::class, 'getEventTicketReport'])->name('event.ticketReport');
    Route::get('/event/{eventId}/mailchimp-report', [LocationController::class, 'getMailchimpEventReport'])->name('event.mailchimpReport');
    
    //EXPORT ROUTES
    Route::get('/location/{locationId}/export', [LocationController::class, 'exportLocationData'])->name('location.export');
    Route::get('/event/{eventId}/export-all', [LocationController::class, 'exportEventData'])->name('event.exportAll');
    
    //SHEETS DATA ROUTES
    Route::get('/location/sheets-data/{eventId}', [LocationController::class, 'getSheetsData'])->name('location.getSheetsData');
    Route::post('/location/save-sheets-data', [LocationController::class, 'saveSheetsData'])->name('location.saveSheetsData');
});

Route::middleware(['auth', RoleMiddleware::class . ':admin'])->group(function () {  
    //DASHBOARD
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/{event}', [DashboardController::class, 'filter'])->name('dashboard.filter');

    //EVENTS ROUTES
    Route::post('/events', [EventsController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [EventsController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventsController::class, 'destroy'])->name('events.destroy');

    //SIGN UP FORM ROUTES
    Route::post('/signup-form/{eventId}', [SignUpFormController::class, 'store'])->name('signup.store');
    Route::get('/signup-form/create/{eventId}', [SignUpFormController::class, 'create'])->name('signup.create');
    Route::post('/signup-form/generate/{eventId}', [SignUpFormController::class, 'generate'])->name('signup.generate');

    Route::delete('/signup-form/{eventId}', [SignUpFormController::class, 'destroy'])->name('signup.destroy');

    //EDIT SIGN UP FORM ROUTES
    Route::get('/signup-form/edit/{formId}', [SignUpFormController::class, 'edit'])->name('signup.edit');
    //UPDATE SIGN UP FORM ROUTES
    Route::post('/signup-form/update/{eventId}', [SignUpFormController::class, 'update'])->name('signup.update');

    //USERS ROUTES
    Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    Route::patch('/users/{userId}', [UsersController::class, 'update'])->name('users.update');
    Route::delete('/users/{userId}', [UsersController::class, 'destroy'])->name('users.destroy');

    //FILMS ROUTES
    Route::post('/films', [FilmsController::class, 'store'])->name('films.store');
    Route::patch('/films/{film}', [FilmsController::class, 'update'])->name('films.update');
    Route::delete('/films/{film}', [FilmsController::class, 'destroy'])->name('films.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Mailchimp Auto-sync Settings Routes
Route::get('/mailchimp/autosync/settings', [MailchimpAutoSyncController::class, 'getSettings'])->name('mailchimp.autosync.settings');
Route::post('/mailchimp/autosync/update', [MailchimpAutoSyncController::class, 'updateSettings'])->name('mailchimp.autosync.update');
Route::get('/mailchimp/autosync/lists', [MailchimpAutoSyncController::class, 'getListsForAccount'])->name('mailchimp.autosync.lists');

// Weekly Report Routes
Route::get('/weekly-report', [App\Http\Controllers\WeeklyReportController::class, 'index'])->name('weekly-report');
Route::match(['get', 'post'], '/weekly-report/generate', [App\Http\Controllers\WeeklyReportController::class, 'generate'])->name('weekly-report.generate');
Route::post('/weekly-report/export', [App\Http\Controllers\WeeklyReportController::class, 'export'])->name('weekly-report.export');
Route::post('/weekly-report/export-pdf', [App\Http\Controllers\WeeklyReportController::class, 'exportPdf'])->name('weekly-report.export-pdf');
Route::get('/weekly-report/event-breakdown', [App\Http\Controllers\WeeklyReportController::class, 'getEventBreakdown'])->name('weekly-report.event-breakdown');
Route::post('/weekly-report/event-breakdown/export-pdf', [App\Http\Controllers\WeeklyReportController::class, 'exportEventBreakdownPdf'])->name('weekly-report.event-breakdown.export-pdf');

// API Routes
Route::prefix('api')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/location/mailchimp/lists', [LocationController::class, 'getMailchimpLists'])->name('location.mailchimpLists');
    Route::get('/location/subscribers', [LocationController::class, 'getSubscribers'])->name('location.getSubscribers');
});

Route::get('/location/mailchimp-logs/download', [LocationController::class, 'downloadMailchimpLogs'])
    ->name('location.downloadMailchimpLogs');

require __DIR__.'/auth.php';
