<?php

use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FlightSearchController;
use App\Http\Controllers\PurchaseTicketController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [FlightSearchController::class, 'index'])->name('home');
Route::post('/language/{locale}', function (Request $request, string $locale): RedirectResponse {
    abort_unless(in_array($locale, ['tr', 'en'], true), 404);

    $request->session()->put('locale', $locale);

    return back();
})->name('language.update');
Route::get('/search', [FlightSearchController::class, 'search']);
Route::post('/search', [FlightSearchController::class, 'search'])->name('flight-search.search');
Route::post('/purchase', PurchaseTicketController::class)->name('tickets.purchase');
Route::post('/api/message', ChatbotController::class)->name('chat.message');
Route::get('/api/message/{sessionId}/{messageId}', [ChatbotController::class, 'show'])->name('chat.message.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
