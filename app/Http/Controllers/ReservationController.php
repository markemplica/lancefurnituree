<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationCancelRequest;
use App\Http\Requests\ReservationStoreRequest;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Services\ReservationStatusNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.reservations.index');
        }

        abort_unless($request->user()->role === 'customer', 403);

        return view('reservations.index', ['reservations' => $request->user()->reservations()
            ->whereDoesntHave('transactions', fn ($query) => $query->where('action', Reservation::CUSTOMER_DELETED_ACTION))
            ->with('product', 'transactions')->latest()->paginate(12)]);
    }

    public function store(ReservationStoreRequest $request)
    {
        $data = $request->validated();
        if ($request->boolean('customization_requested')) {
            $customDetails = collect([
                'Preferred wood' => $data['custom_wood_type'] ?? null,
                'Preferred finish' => $data['custom_varnish_finish'] ?? null,
                'Design/details' => $data['custom_design_notes'] ?? null,
            ])->filter(fn ($value) => filled($value));
            $customNote = "CUSTOM FURNITURE REQUEST:\n".$customDetails->map(fn ($value, $label) => $label.': '.$value)->implode("\n");
            $customerNotes = trim((string) ($data['notes'] ?? ''));
            $data['notes'] = $customNote.($customerNotes !== '' ? "\n\nCUSTOMER NOTES:\n".$customerNotes : '');
        }
        unset($data['customization_requested'], $data['custom_wood_type'], $data['custom_varnish_finish'], $data['custom_design_notes']);
        $product = FurnitureProduct::where('status', 'active')->findOrFail($data['furniture_product_id']);
        if (! empty($data['recommendation_id'])) {
            $recommendation = Recommendation::where('user_id', $request->user()->id)->findOrFail($data['recommendation_id']);
            abort_unless($recommendation->items()->where('furniture_product_id', $product->id)->exists(), 422, 'That item is not part of this recommendation.');
        }
        $reservation = DB::transaction(function () use ($data, $request) {
            // Lock an existing row even when there are no previous reservations.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $duplicate = Reservation::where('user_id', $request->user()->id)->where('furniture_product_id', $data['furniture_product_id'])->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->exists();
            if ($duplicate) return null;
            $reservation = Reservation::create(array_merge($data, ['reference' => 'FC-'.now()->format('ymd').'-'.strtoupper(Str::random(6)), 'user_id' => $request->user()->id, 'expires_at' => now()->addDays(7)]));
            $reservation->transactions()->create(['actor_id' => $request->user()->id, 'action' => 'submitted', 'notes' => 'Reservation request submitted by customer.']);
            return $reservation;
        });
        if (! $reservation) {
            throw \Illuminate\Validation\ValidationException::withMessages(['furniture_product_id' => 'You already have an active request for this item.']);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => "Reservation {$reservation->reference} was submitted for review. No payment has been collected.", 'reference' => $reservation->reference], 201);
        }
        return redirect()->route('reservations.index')->with('success', "Reservation {$reservation->reference} was submitted for review. No payment has been collected.");
    }

    public function cancel(ReservationCancelRequest $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        $data = $request->validated();
        $notifier = app(ReservationStatusNotifier::class);
        [$reservation, $log] = DB::transaction(function () use ($request, $reservation, $data, $notifier) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($reservation->status, ['pending', 'confirmed']), 422, 'Only active reservations can be cancelled.');
            $reservation->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'] ?? null]);
            $reservation->transactions()->create(['actor_id' => $request->user()->id, 'action' => 'cancelled', 'notes' => $data['cancellation_reason'] ?? 'Cancelled by customer.']);
            return [$reservation, $notifier->record($reservation, 'cancelled')];
        });
        $notifier->dispatch($reservation, $log);
        return back();
    }

    public function destroy(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($request, $reservation) {
            $current = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $request->user()->id, 403);
            abort_unless($current->status === 'cancelled', 422, 'Only cancelled requests can be removed from your list.');
            // Customer list removal preserves the shop's reservation and cancellation history.
            $current->transactions()->firstOrCreate(['action' => Reservation::CUSTOMER_DELETED_ACTION], [
                'actor_id' => $request->user()->id,
                'notes' => 'Removed from the customer reservation list.',
            ]);
        });

        return redirect()->route('reservations.index');
    }
}
