<?php

namespace Tests\Feature;

use App\Models\{FurnitureProduct, Reservation, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReservationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function reservation(User $user, string $status = 'cancelled'): Reservation
    {
        $number = ++$this->sequence;
        $product = FurnitureProduct::create(['name'=>'Deletion fixture '.$number,'slug'=>'deletion-fixture-'.$number,
            'category'=>'Table','description'=>'Test fixture','wood_type'=>'Wood','varnish_finish'=>'Natural','style'=>'Modern','price'=>1000,'status'=>'active']);
        return Reservation::create(['reference'=>'FC-DELETE-'.$number,'user_id'=>$user->id,'furniture_product_id'=>$product->id,
            'branch'=>config('furnicraft.branch'),'status'=>$status,'cancellation_reason'=>$status==='cancelled' ? 'Changed plans.' : null]);
    }

    public function test_customer_can_remove_a_cancelled_request_once_and_shop_history_remains(): void
    {
        Notification::fake();
        $customer=User::factory()->create(['role'=>'customer']);
        $reservation=$this->reservation($customer);
        $reservation->transactions()->create(['actor_id'=>$customer->id,'action'=>'cancelled','notes'=>'Changed plans.']);
        $url=route('reservations.destroy',$reservation);
        $this->actingAs($customer)->get(route('reservations.index'))->assertOk()->assertSee($reservation->reference)->assertSee('data-delete-reservation',false);
        $this->delete($url)->assertRedirect(route('reservations.index'))->assertSessionMissing('success');
        $this->delete($url)->assertRedirect(route('reservations.index'));
        $this->get(route('reservations.index'))->assertOk()->assertDontSee($reservation->reference);
        $this->assertSame('cancelled',$reservation->fresh()->status);
        $this->assertSame('Changed plans.',$reservation->fresh()->cancellation_reason);
        $this->assertSame(1,$reservation->transactions()->where('action',Reservation::CUSTOMER_DELETED_ACTION)->count());
        $this->assertSame(1,$reservation->transactions()->where('action','cancelled')->count());
        $this->assertSame(['pending','cancelled'],$reservation->fresh()->statusTimeline()->pluck('status')->all());
        $admin=User::factory()->create(['role'=>'admin']);
        $this->actingAs($admin)->get(route('admin.reservations.index'))->assertOk()->assertSee($reservation->reference);
        Notification::assertNothingSent();
    }

    public function test_other_customers_and_non_cancelled_requests_cannot_be_deleted(): void
    {
        $owner=User::factory()->create(['role'=>'customer']);
        $other=User::factory()->create(['role'=>'customer']);
        $cancelled=$this->reservation($owner);
        $this->actingAs($other)->delete(route('reservations.destroy',$cancelled))->assertForbidden();
        foreach(['pending','confirmed','declined','completed','no_show'] as $status) {
            $reservation=$this->reservation($owner,$status);
            $this->actingAs($owner)->delete(route('reservations.destroy',$reservation))->assertStatus(422);
            $this->assertSame($status,$reservation->fresh()->status);
        }
        $this->assertDatabaseMissing('reservation_transactions',['action'=>Reservation::CUSTOMER_DELETED_ACTION]);
    }

    public function test_cancellation_keeps_the_reason_and_does_not_flash_the_removed_banner(): void
    {
        Notification::fake();
        $customer=User::factory()->create(['role'=>'customer']);
        $reservation=$this->reservation($customer,'pending');
        $this->actingAs($customer)->from(route('reservations.index'))->patch(route('reservations.cancel',$reservation),[
            'cancellation_reason'=>'My schedule changed.',
        ])->assertRedirect(route('reservations.index'))->assertSessionMissing('success');
        $this->assertSame('cancelled',$reservation->fresh()->status);
        $this->assertSame('My schedule changed.',$reservation->fresh()->cancellation_reason);
        $this->get(route('reservations.index'))->assertOk()->assertDontSee('The reservation request was cancelled.')->assertSee('data-delete-reservation',false);
    }
}
