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

Route::get('/logs', function () {
    return file_get_contents(storage_path('logs/laravel.log'));
});

Route::get('/', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::get('/form/{event_uuid}', [SignUpFormController::class, 'embed'])->name('signup.embed');
Route::post('/form/{event_uuid}', [SignUpFormController::class, 'storeEmbeddedData'])->name('signup.storeEmbedded');

//SHARED ROUTES
Route::middleware(['auth', RoleMiddleware::class . ':admin,host'])->group(function () {
    //events page
    Route::get('/events', [EventsController::class, 'index'])->name('events.index');

    //ATTENDEES ROUTES
    Route::get('/attendees/{eventId}', [AttendeesController::class, 'index'])->name('attendees.index');
    Route::put('/attendees/{id}', [AttendeesController::class, 'update'])->name('attendees.update');
    Route::delete('/attendee/{attendee}/event/{event}', [AttendeesController::class, 'destroy'])->name('attendees.destroy');

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

    //IMPORT DATA TO MAILCHIMP ROUTES
    Route::post('/location/import-data-to-mailchimp', [LocationController::class, 'importDataToMailChimp'])->name('location.importDataToMailChimp');
    Route::get('/api/location/mailchimp/lists', [LocationController::class, 'getMailchimpLists'])->name('location.mailchimpLists');
    Route::get('/api/location/subscribers', [LocationController::class, 'getSubscribers'])->name('location.getSubscribers');
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

    //LOCATION ROUTES
    // Route::get('/location', [LocationController::class, 'index'])->name('location.index');
    // Route::get('/location/{eventId}', [LocationController::class, 'locationpage'])->name('location.locationpage');
    // Route::put('/location/{location}/password', [LocationController::class, 'updatePassword'])->name('location.updatePassword');
    // Route::put('/location/update-all-passwords/{event}', [LocationController::class, 'updateAllPasswords'])->name('location.updateAllPasswords');
    // Route::put('/event/{event}/password', [EventsController::class, 'updatePassword'])->name('event.updatePassword');
    // Route::post('/location', [LocationController::class, 'store'])->name('location.store');
    // Route::put('/location/{location}', [LocationController::class, 'update'])->name('location.update');
    // Route::delete('/location/{location}', [LocationController::class, 'destroy'])->name('location.destroy');

    //EDIT SIGN UP FORM ROUTES
    Route::get('/signup-form/edit/{formId}', [SignUpFormController::class, 'edit'])->name('signup.edit');
    //UPDATE SIGN UP FORM ROUTES
    Route::post('/signup-form/update/{eventId}', [SignUpFormController::class, 'update'])->name('signup.update');

    //USERS ROUTES
    Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    Route::patch('/users/{userId}', [UsersController::class, 'update'])->name('users.update');
    Route::delete('/users/{userId}', [UsersController::class, 'destroy'])->name('users.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
