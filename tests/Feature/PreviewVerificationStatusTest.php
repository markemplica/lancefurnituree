<?php

namespace Tests\Feature;

use App\Models\{User, RoomImage, FurnitureProduct, RoomPreviewGeneration};
use App\Services\{GeminiService, RoomPreviewRasterService, RoomPreviewV2Service};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreviewVerificationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_ai_image_does_not_mark_local_composite_verified(): void
    {
        $this->checkSavedArtifact(false);
    }

    public function test_accepted_ai_image_is_saved_as_verified(): void
    {
        $this->checkSavedArtifact(true);
    }

    public function test_generation_timeout_saves_local_estimate(): void
    {
        $this->checkSavedArtifact(false, 'timeout');
    }

    public function test_generation_quota_error_saves_local_estimate(): void
    {
        $this->checkSavedArtifact(false, 'quota');
    }

    private function checkSavedArtifact(bool $guardPasses, ?string $failure = null): void
    {
        Storage::fake('local');
        config(['roompreview.max_generation_attempts' => 1, 'roompreview.use_v2_pipeline' => true]);
        $user = User::factory()->create();
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/test.png', 'gemini_consent_at' => now(), 'analysis_status' => 'completed']);
        Storage::disk('local')->put($room->image_path, 'room-bytes');
        $product = FurnitureProduct::create(['name' => 'Test chair', 'slug' => 'test-chair', 'description' => 'Test chair', 'category' => 'Chair', 'wood_type' => 'Wood', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 100, 'status' => 'active', 'width_cm' => 60, 'depth_cm' => 60, 'height_cm' => 90]);
        $generation = RoomPreviewGeneration::create(['room_image_id' => $room->id, 'furniture_product_id' => $product->id, 'status' => 'queued', 'cache_key' => str_repeat('a', 64)]);
        $photos = $this->mock(\App\Services\RoomPhotoService::class);
        $photos->shouldReceive('exists')->andReturn(true);
        $photos->shouldReceive('get')->andReturn('room-bytes');
        $photos->shouldReceive('storeGenerated')->andReturnUsing(function ($path, $bytes) { Storage::disk('local')->put($path, $bytes); });
        $photos->shouldReceive('deleteGenerated')->andReturnNull();
        $gemini = $this->mock(GeminiService::class);
        $gemini->shouldReceive('providerCallCount')->andReturn(0);
        $gemini->shouldReceive('limitProviderRequests')->once()->with(2);
        $gemini->shouldReceive('clearProviderRequestLimit')->once();
        $room->update(['analysis_status'=>'completed','analysis'=>[
            'gemini_used' => true,
            'analyzed_furniture_type' => 'all catalog furniture',
            'placement_profile_version' => 2,
            'candidate_zones' => [[
                'surface' => 'floor',
                'anchor_x_percent' => 50,
                'anchor_y_percent' => 86,
                'max_width_percent' => 55,
                'suitable_types' => ['Chair'],
                'clearance_score' => 90,
                'occlusion_score' => 5,
                'confidence' => 'high',
                'reason' => 'Clear floor area.',
            ]],
        ]]);
        $gemini->shouldNotReceive('analyzeRoom');
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        if ($failure) {
            $exception = $failure === 'timeout'
                ? new \Illuminate\Http\Client\ConnectionException('Test timeout')
                : new \Illuminate\Http\Client\RequestException(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(429)));
            $gemini->shouldReceive('furnishRoom')->once()->andThrow($exception);
            $gemini->shouldNotReceive('verifyGeneratedRoom');
        } else {
            $gemini->shouldReceive('furnishRoom')->once()->andReturn(['bytes' => 'ai-bytes', 'mime_type' => 'image/png']);
            if ($guardPasses) $gemini->shouldReceive('verifyGeneratedRoom')->once()->andReturn(['passed' => true]);
            else $gemini->shouldNotReceive('verifyGeneratedRoom');
        }
        $raster = $this->mock(RoomPreviewRasterService::class);
        $raster->shouldReceive('normalize')->once()->andReturn(['bytes' => 'normalized-room', 'mime' => 'image/png', 'width' => 800, 'height' => 600, 'quality' => ['usable' => true]]);
        if ($failure) $raster->shouldNotReceive('pixelGuard');
        else $raster->shouldReceive('pixelGuard')->once()->andReturn(['supported' => true, 'passed' => $guardPasses]);
        $raster->shouldReceive('available')->andReturn(true);
        // The deterministic 2D composite is prepared first for fallback and placement bounds.
        $raster->shouldReceive('composite')->once()->andReturn(['bytes' => 'local-bytes', 'mime' => 'image/png']);
        app(RoomPreviewV2Service::class)->process($generation->id);
        $generation->refresh();
        $this->assertSame($failure === 'quota' ? 'ai_request_limit_reached' : ($failure ? 'ai_generation_unavailable' : ($guardPasses ? null : 'generated_image_failed_local_checks')), $generation->failure_code);
        $this->assertSame($guardPasses ? 'completed' : 'fallback', $generation->status);
        $this->assertSame($guardPasses ? 'ai-bytes' : 'local-bytes', Storage::disk('local')->get($generation->placement_json['artifact_path']));
        if (!$guardPasses) {
            $this->assertNull($generation->placement_json['verification']);
            $this->assertNull($generation->placement_json['pixel_guard']);
            $this->assertNull($generation->metrics_json['verification_passed']);
            $this->assertStringNotContainsString('Verified', $generation->message);
        }
    }
}
