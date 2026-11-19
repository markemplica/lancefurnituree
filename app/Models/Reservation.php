<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Reservation extends Model
{
    public const CUSTOMER_DELETED_ACTION = 'customer_deleted';

    protected $fillable = ['reference', 'user_id', 'furniture_product_id', 'recommendation_id', 'branch', 'preferred_visit_at', 'expires_at', 'notes', 'status', 'decline_reason', 'cancellation_reason', 'confirmed_at', 'confirmed_by', 'item_ready_confirmed_at', 'reminder_sent_at', 'completed_at', 'no_show_at'];

    protected function casts(): array
    {
        return ['preferred_visit_at' => 'datetime', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'item_ready_confirmed_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'completed_at' => 'datetime', 'no_show_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(FurnitureProduct::class, 'furniture_product_id');
    }

    public function recommendation()
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function transactions()
    {
        return $this->hasMany(ReservationTransaction::class);
    }

    public function statusTimeline(): Collection
    {
        $labels = [
            'pending' => 'Pending', 'confirmed' => 'Confirmed', 'ready' => 'Ready',
            'completed' => 'Completed', 'cancelled' => 'Cancelled',
            'declined' => 'Declined', 'no_show' => 'No-show',
        ];
        $events = collect([['status' => 'pending', 'label' => 'Pending', 'at' => $this->created_at, 'order' => 0]]);
        foreach ($this->transactions->sortBy('id') as $transaction) {
            $status = match ($transaction->action) {
                'confirmed', 'pending', 'completed', 'cancelled', 'declined', 'no_show' => $transaction->action,
                'item_ready_confirmed' => 'ready',
                'expired' => 'cancelled',
                default => null,
            };
            if ($status !== null) {
                $events->push(['status' => $status, 'label' => $labels[$status], 'at' => $transaction->created_at, 'order' => $transaction->id + 100]);
            }
        }

        foreach ([
            'confirmed' => $this->confirmed_at,
            'ready' => $this->item_ready_confirmed_at,
            'completed' => $this->completed_at,
            'no_show' => $this->no_show_at,
        ] as $status => $at) {
            if ($at && ! $events->contains('status', $status)) {
                $events->push(['status' => $status, 'label' => $labels[$status], 'at' => $at, 'order' => 10]);
            }
        }

        if (! $events->contains('status', $this->status)) {
            $events->push(['status' => $this->status, 'label' => $labels[$this->status], 'at' => $this->updated_at, 'order' => 1000000]);
        }

        return $events->sort(fn (array $left, array $right) => ($left['at'] <=> $right['at']) ?: ($left['order'] <=> $right['order']))
            ->reduce(function (Collection $timeline, array $event): Collection {
                if (($timeline->last()['status'] ?? null) !== $event['status']) {
                    $timeline->push(collect($event)->except('order')->all());
                }
                return $timeline;
            }, collect())
            ->values();
    }
}

