<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseTickets;
use App\Http\Requests\PurchaseTicketRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PurchaseTicketController extends Controller
{
    public function __invoke(PurchaseTicketRequest $request, PurchaseTickets $purchaseTickets): RedirectResponse
    {
        $pnr = $purchaseTickets->execute([
            ...$request->validated(),
            'user_id' => $request->user()?->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Booking confirmed for {$pnr->first_name} {$pnr->last_name}.",
        ]);

        return to_route('home');
    }
}
