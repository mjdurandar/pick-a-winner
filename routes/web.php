<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventsController;


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
    Route::get('/events-page/{event}/create-form', [EventsController::class, 'createForm'])->name('form-builder.create');
    Route::post('/events-page/{event}', [EventsController::class, 'storeForm'])->name('form-builder.store');

    //PROFILE PAGE
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
