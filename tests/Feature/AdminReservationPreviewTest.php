<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminReservationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(): Reservation
    {
        Storage::fake('local');
        Storage::disk('local')->put('rooms/sample.png', UploadedFile::fake()->image('room.png', 800, 600)->get());
        $customer = User::factory()->create();
        $preference = $customer->preference()->create(['furniture_type' => 'Chair', 'budget_min' => 0, 'budget_max' => 20000]);
        $room = RoomImage::create(['user_id' => $customer->id, 'image_path' => 'rooms/sample.png', 'analysis_status' => 'fallback',
            'analysis' => app(GeminiService::class)->roomFallback(null, null, 'Chair'), 'gemini_consent_at' => now()]);
        $product = FurnitureProduct::create(['name' => 'Reserved Chair', 'slug' => 'reserved-chair', 'category' => 'Chair', 'description' => 'Chair',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active', 'image_path' => 'demo:chair.svg']);
        $recommendation = Recommendation::create(['user_id' => $customer->id, 'customer_preference_id' => $preference->id, 'room_image_id' => $room->id, 'summary' => 'Test']);

        return Reservation::create(['user_id' => $customer->id, 'recommendation_id' => $recommendation->id, 'furniture_product_id' => $product->id, 'reference' => 'FC-PREVIEW', 'branch' => 'Test', 'status' => 'pending']);
    }

    public function test_admin_can_view_linked_local_preview_without_any_provider_or_job(): void
    {
        $reservation = $this->reservation();
        Http::fake();
        Bus::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.reservations.index'))->assertOk()->assertSee('<th>2D preview</th>', false)->assertSee('View preview');
        $this->get(route('admin.reservations.preview', $reservation))->assertOk()->assertSee('Reserved Chair')
            ->assertSee('reconstructed')->assertSee('data-preview-v2-enabled="false"', false)->assertDontSee('data-verify-url', false);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_private_saved_artifact_is_restricted_to_admin_and_matching_room_product(): void
    {
        $reservation = $this->reservation();
        $room = $reservation->recommendation->roomImage;
        $bytes = UploadedFile::fake()->image('saved.png', 640, 480)->get();
        Storage::disk('local')->put('rooms/saved.png', $bytes);
        RoomPreviewGeneration::create(['room_image_id' => $room->id, 'furniture_product_id' => $reservation->furniture_product_id,
            'status' => 'completed', 'placement_json' => ['artifact_path' => 'rooms/saved.png']]);
        $this->get(route('admin.reservations.preview', $reservation))->assertRedirectContains('/login');
        $this->actingAs($reservation->user)->get(route('admin.reservations.preview-image', $reservation))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.reservations.preview', $reservation))->assertOk()->assertSee('Saved generated preview');
        $response = $this->get(route('admin.reservations.preview-image', $reservation))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $room->update(['gemini_consent_at' => null]);
        $this->get(route('admin.reservations.preview-image', $reservation))->assertNotFound();
    }

    public function test_missing_and_foreign_room_links_do_not_expose_unrelated_photos(): void
    {
        $reservation = $this->reservation();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $room = $reservation->recommendation->roomImage;
        $room->update(['user_id' => User::factory()->create()->id]);
        $this->get(route('admin.reservations.preview', $reservation))->assertNotFound();
        $this->get(route('admin.reservations.preview-image', $reservation))->assertNotFound();
        $reservation->update(['recommendation_id' => null]);
        $this->get(route('admin.reservations.index'))->assertOk()->assertSee('Unavailable')->assertDontSee('View preview');
    }

    public function test_deleted_room_file_shows_unavailable_instead_of_an_invented_preview(): void
    {
        $reservation = $this->reservation();
        Storage::disk('local')->delete('rooms/sample.png');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.reservations.index'))
            ->assertOk()->assertSee('Unavailable')->assertDontSee('View preview');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.reservations.preview', $reservation))
            ->assertOk()->assertSee('room photo is no longer available')->assertDontSee('data-room-preview-canvas', false);
    }

    public function test_admin_can_manually_mark_doorway_corners_when_analysis_has_no_valid_quad(): void
    {
        $reservation = $this->reservation();
        $reservation->product->update(['category' => 'Door', 'width_cm' => 80, 'depth_cm' => 4, 'height_cm' => 210]);
        $reservation->recommendation->roomImage->update(['analysis' => [
            'openings' => [['type' => 'doorway', 'bbox' => [20, 10, 60, 80]]],
        ]]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.reservations.preview', $reservation))
            ->assertOk()
            ->assertSee('data-manual-door-placement', false)
            ->assertSee('Mark doorway corners')
            ->assertSee('data-room-id="'.$reservation->recommendation->room_image_id.'"', false);
    }
}
