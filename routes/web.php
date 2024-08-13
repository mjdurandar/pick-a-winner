<?php

use App\Http\Controllers\AttendeesController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\FormBuilderController;

Route::get('/', function () {
    return view('auth.login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {

    //EVENTS PAGE
    Route::get('/events-page', [EventsController::class, 'index'])->name('events.index');
    Route::post('/events-page', [EventsController::class, 'store'])->name('events.store');

    //FORM BUILDER
    Route::get('/form-builder/{event}', [FormBuilderController::class, 'createForm'])->name('form-builder.create');
    Route::post('/form-builder/{event}', [FormBuilderController::class, 'storeForm'])->name('form-builder.store');
    Route::get('/form-builder/view/{event}', [FormBuilderController::class, 'viewForm'])->name('form-builder.view');
    Route::post('/form-builder/submit/{event}', [FormBuilderController::class, 'submitForm'])->name('form-builder.submit');

    //ATTENDEES PAGE
    Route::get('/attendees-page', [AttendeesController::class, 'index'])->name('attendees.index');
    Route::get('/attendees-page/{event}', [AttendeesController::class, 'show'])->name('attendees.show');


    //PROFILE PAGE
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
