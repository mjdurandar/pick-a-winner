<?php

use App\Http\Controllers\AttendeesController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\PickaWinnerController;
use App\Http\Controllers\SignUpFormController;
use App\Http\Controllers\PrizeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/users', function () {
    return Inertia::render('Users');
})->middleware(['auth', 'verified'])->name('users');

Route::get('/adventureentertainment/form/{eventId}', [SignUpFormController::class, 'embed'])->name('signup.embed');
Route::post('/adventureentertainment/form/{eventId}', [SignUpFormController::class, 'storeEmbeddedData'])->name('signup.storeEmbedded');

Route::middleware('auth')->group(function () {

    //EVENTS ROUTES
    Route::get('/events', [EventsController::class, 'index'])->name('events.index');
    Route::post('/events', [EventsController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [EventsController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventsController::class, 'destroy'])->name('events.destroy');

    //SIGN UP FORM ROUTES
    Route::get('/signup-form/{eventId}', [SignUpFormController::class, 'index'])->name('signup.index');
    Route::post('/signup-form/{eventId}', [SignUpFormController::class, 'store'])->name('signup.store');
    Route::post('/signup-form', [SignUpFormController::class, 'generate'])->name('signup.generate');
    Route::delete('/signup-form/{eventId}', [SignUpFormController::class, 'destroy'])->name('signup.destroy');

    //EDIT SIGN UP FORM ROUTES
    Route::get('/signup-form/edit/{formId}', [SignUpFormController::class, 'edit'])->name('signup.edit');
    //UPDATE SIGN UP FORM ROUTES
    Route::post('/signup-form/update/{eventId}', [SignUpFormController::class, 'update'])->name('signup.update');

    //PICK A WINNER ROUTES
    Route::get('/pickawinner', [PickaWinnerController::class, 'index'])->name('pickawinner.index');
    Route::get('/pickawinner/{eventId}', [PickaWinnerController::class, 'pickawinner'])->name('pickawinner.page');
    Route::get('/pickawinner/show/{location}/{event}', [PickaWinnerController::class, 'pickawinnerlocationpage'])->name('pickawinner.locationpage');

    // PICK A WINNER ALL LOCATION ROUTES
    Route::get('/pickawinner/alllocation/{event}', [PickaWinnerController::class, 'alllocation'])->name('pickawinner.alllocation');

    //PRIZE ROUTES
    Route::patch('/prize/{prize}', [PrizeController::class, 'update'])->name('prize.update');
    Route::post('/prize', [PrizeController::class, 'addPrize'])->name('prize.store');
    Route::delete('/prize/{prize}', [PrizeController::class, 'destroy'])->name('prize.destroy');
    Route::post('/prize/winner/{prize}', [PrizeController::class, 'addWinner'])->name('prize.assignWinner');

    //ATTENDEES ROUTES
    Route::get('/attendees/{eventId}', [AttendeesController::class, 'index'])->name('attendees.index');
    Route::put('/attendees/{id}', [AttendeesController::class, 'update'])->name('attendees.update');
    Route::delete('/attendee/{attendee}/event/{event}', [AttendeesController::class, 'destroy'])->name('attendees.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
