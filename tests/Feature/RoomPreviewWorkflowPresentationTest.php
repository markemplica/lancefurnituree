<?php

namespace Tests\Feature;

use App\Models\{CustomerPreference, FurnitureProduct, Recommendation, RecommendationItem, RoomImage, User};
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomPreviewWorkflowPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_preview_pages_expose_the_enhancement_rollout_without_starting_processing(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $user = User::factory()->create(['role' => 'customer']);
        $preference = CustomerPreference::create(['user_id' => $user->id, 'furniture_type' => 'Table', 'budget_max' => 100000]);
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/presentation.png', 'analysis_status' => 'fallback', 'analysis' => ['gemini_used' => false]]);
        $product = FurnitureProduct::create([
            'name' => 'Presentation Table', 'slug' => 'presentation-table', 'category' => 'Table',
            'description' => 'A table for a 2D preview.', 'wood_type' => 'Wood', 'varnish_finish' => 'Natural',
            'style' => 'Modern', 'price' => 10000, 'status' => 'active',
            'width_cm' => 120, 'depth_cm' => 80, 'height_cm' => 75,
        ]);
        $recommendation = Recommendation::create([
            'user_id' => $user->id, 'customer_preference_id' => $preference->id,
            'room_image_id' => $room->id, 'furniture_type' => 'Table',
            'summary' => 'Local recommendation summary.',
            'algorithm_version' => RecommendationService::ALGORITHM_VERSION,
        ]);
        RecommendationItem::create(['recommendation_id' => $recommendation->id, 'furniture_product_id' => $product->id, 'position' => 1, 'explanation' => 'A test match.']);

        foreach ([[false, 100, 'false'], [true, 0, 'false'], [true, 100, 'true']] as [$enabled, $rollout, $expected]) {
            config(['roompreview.use_v2_pipeline' => $enabled, 'roompreview.rollout_percent' => $rollout]);
            foreach ([route('room.visualize', [$room, 'product' => $product->id]), route('recommendations.show', $recommendation)] as $url) {
                $this->actingAs($user)->get($url)->assertOk()
                    ->assertSee('data-preview-v2-enabled="'.$expected.'"', false)
                    ->assertSee('The exact catalog image is placed automatically in a 2D preview.')
                    ->assertSee('Download preview (PNG)');
            }
        }
        \Illuminate\Support\Facades\Bus::assertNothingDispatched();
        $this->assertDatabaseCount('room_preview_generations', 0);
    }

    public function test_step_two_explains_photo_quality_and_visual_fit_without_manual_controls(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        CustomerPreference::create(['user_id' => $user->id, 'furniture_type' => 'Table', 'budget_max' => 100000]);

        $this->actingAs($user)->get(route('preferences.edit'))->assertOk()
            ->assertSee('Room photo')
            ->assertSee('A 2D preview is a visual estimate.')
            ->assertDontSee('Room measurements &amp; notes', false)
            ->assertSee('data-room-dropzone', false)
            ->assertSee('Show the floor and nearby walls')
            ->assertSee('For doors, include the full doorway.')
            ->assertDontSee('data-product-scale', false)
            ->assertDontSee('data-product-rotation', false);
    }

    public function test_pending_door_analysis_is_not_presented_as_a_failed_placement_on_either_preview_page(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $preference = CustomerPreference::create(['user_id' => $user->id, 'furniture_type' => 'Door', 'budget_max' => 100000]);
        $room = RoomImage::create([
            'user_id' => $user->id, 'image_path' => 'rooms/pending-door.png',
            'analysis_status' => 'processing', 'analysis' => ['gemini_used' => false],
            'gemini_consent_at' => now(),
        ]);
        $product = FurnitureProduct::create([
            'name' => 'Preview Door', 'slug' => 'preview-door', 'category' => 'Door',
            'description' => 'A door awaiting room analysis.', 'wood_type' => 'Wood',
            'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 10000,
            'status' => 'active', 'width_cm' => 90, 'depth_cm' => 4, 'height_cm' => 210,
        ]);
        $recommendation = Recommendation::create([
            'user_id' => $user->id, 'customer_preference_id' => $preference->id,
            'room_image_id' => $room->id, 'furniture_type' => 'Door',
            'summary' => 'Local recommendation summary.',
            'algorithm_version' => RecommendationService::ALGORITHM_VERSION,
        ]);
        RecommendationItem::create([
            'recommendation_id' => $recommendation->id,
            'furniture_product_id' => $product->id,
            'position' => 1, 'explanation' => 'A test match.',
        ]);

        foreach ([route('room.visualize', [$room, 'product' => $product->id]), route('recommendations.show', $recommendation)] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Analyzing doorway in your photo')
                ->assertDontSee('No validated placement available');
        }

        $room->update([
            'analysis_status' => 'fallback',
            'analysis_error' => 'Gemini has reached its request limit.',
            'analysis_requested_at' => now(),
        ]);
        $this->actingAs($user)->get(route('room.visualize', [$room, 'product' => $product->id]))->assertOk()
            ->assertSee('No confirmed doorway available')
            ->assertSee('No door has been placed because a complete doorway could not be confirmed.')
            ->assertSee('data-manual-door-placement', false)
            ->assertSee('Mark doorway corners')
            ->assertSee('Original photo shown');
    }
}
