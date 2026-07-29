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
        $validated = $request->validated();

        $order = $purchaseTickets->execute([
            'offer_ids' => array_map('intval', $validated['offer_ids']),
            'first_name' => (string) $validated['first_name'],
            'last_name' => (string) $validated['last_name'],
            'email' => isset($validated['email']) ? (string) $validated['email'] : null,
            'passport_number' => isset($validated['passport_number']) ? (string) $validated['passport_number'] : null,
            'user_id' => $request->user()?->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Booking {$order->booking_reference} confirmed for {$order->first_name} {$order->last_name}.",
        ]);

        return to_route('home');
    }
}
