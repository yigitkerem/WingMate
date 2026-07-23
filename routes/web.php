<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FlightSearchController;
use App\Http\Controllers\PurchaseTicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FlightSearchController::class, 'index'])->name('home');
Route::get('/search', fn () => to_route('home'));
Route::post('/search', [FlightSearchController::class, 'search'])->name('flight-search.search');
Route::post('/purchase', PurchaseTicketController::class)->name('tickets.purchase');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
