<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeRoomImage;
use App\Models\{FurnitureProduct, RoomImage, RoomPreviewAnalysis, RoomPreviewGeneration, User};
use App\Services\{AiRequestBudgetExceeded, AiTelemetry, FurniturePlacementService, GeminiService, PrivacyAudit, RoomPhotoService, RoomPreviewRasterService, RoomPreviewV2Service};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\{ConnectionException, RequestException, Response};
use Illuminate\Support\Facades\{Cache, Http, Storage};
use Tests\TestCase;

class AutomaticRoomFittingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function pipeline(): array
    {
        Storage::fake('local');
        config(['roompreview.max_generation_attempts' => 1, 'roompreview.use_v2_pipeline' => true]);
        $room = RoomImage::create([
            'user_id' => User::factory()->create()->id, 'image_path' => 'rooms/source.png',
            'image_sha256' => str_repeat('a', 64), 'gemini_consent_at' => now(),
            'analysis_status' => 'completed', 'analysis_requested_at' => now(),
            'analysis' => ['gemini_used' => true, 'placement_profile_version' => 2, 'analyzed_furniture_type' => 'all catalog furniture'],
        ]);
        $product = FurnitureProduct::create([
            'name' => 'Catalog chair', 'slug' => 'catalog-chair', 'description' => 'Chair',
            'category' => 'Chair', 'wood_type' => 'Wood', 'varnish_finish' => 'Natural', 'style' => 'Modern',
            'price' => 100, 'status' => 'active', 'width_cm' => 60, 'depth_cm' => 60, 'height_cm' => 90,
        ]);
        $generation = RoomPreviewGeneration::create([
            'room_image_id' => $room->id, 'furniture_product_id' => $product->id,
            'status' => 'queued', 'cache_key' => str_repeat('b', 64),
        ]);
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('exists')->andReturnTrue();
        $photos->shouldReceive('get')->andReturn('room-bytes');
        $photos->shouldReceive('storeGenerated')->andReturnUsing(fn ($path, $bytes) => Storage::disk('local')->put($path, $bytes));
        $photos->shouldReceive('deleteGenerated')->andReturnUsing(fn ($path) => Storage::disk('local')->delete($path));
        $gemini = $this->mock(GeminiService::class);
        $gemini->shouldReceive('providerCallCount')->andReturn(0);
        $gemini->shouldReceive('limitProviderRequests', 'clearProviderRequestLimit')->byDefault()->andReturnNull();
        $gemini->shouldReceive('isConfigured')->andReturnTrue();
        $gemini->shouldReceive('roomFallback')->andReturn(['gemini_used' => false]);
        $raster = $this->mock(RoomPreviewRasterService::class);
        $raster->shouldReceive('normalize')->byDefault()->andReturn([
            'bytes' => 'normalized-room', 'mime' => 'image/png', 'width' => 800, 'height' => 600, 'quality' => ['usable' => true],
        ]);
        $raster->shouldReceive('composite')->byDefault()->andReturn(['bytes' => 'local-image', 'mime' => 'image/png', 'bounds' => [200, 240, 400, 480]]);
        $raster->shouldReceive('available')->andReturnTrue();
        $placement = $this->mock(FurniturePlacementService::class);
        $placement->shouldReceive('forProduct')->byDefault()->andReturn([
            'renderable' => true, 'x_percent' => 37.5, 'y_percent' => 80, 'max_width_percent' => 25,
            'rotation_deg' => 5, 'perspective_deg' => 8, 'flip' => -1, 'score' => 75,
        ]);
        return [$room, $generation, $gemini, $raster, $placement, $photos];
    }

    public function test_upload_geometry_is_reused_and_server_placement_reaches_the_image_generator(): void
    {
        [$room, $generation, $gemini, $raster] = $this->pipeline();
        $gemini->shouldNotReceive('analyzeRoom');
        $gemini->shouldReceive('furnishRoom')->once()->withArgs(fn ($path, $product, $throws, $models, $retries, $placement) =>
            $placement['x_percent'] === 37.5 && $placement['flip'] === -1
            && $placement['bounds_percent'] == [25, 40, 50, 80]
        )->andReturn(['bytes' => 'ai-image', 'mime_type' => 'image/png']);
        $gemini->shouldReceive('verifyGeneratedRoom')->once()->andReturn(['passed' => true]);
        $raster->shouldReceive('pixelGuard')->once()->with('normalized-room', 'ai-image', [200, 240, 400, 480])->andReturn(['supported' => true, 'passed' => true]);
        app(RoomPreviewV2Service::class)->process($generation->id);
        $this->assertSame('completed', $generation->fresh()->status);
        $this->assertSame('ai-image', Storage::disk('local')->get($generation->fresh()->placement_json['artifact_path']));
        $this->assertSame('completed', $room->fresh()->analysis_status);
    }

    public function test_rejected_enhancement_never_generates_again_even_when_configuration_requests_more_attempts(): void
    {
        [, $generation, $gemini, $raster] = $this->pipeline();
        config(['roompreview.max_generation_attempts'=>99,'roompreview.max_provider_calls'=>99]);
        $gemini->shouldReceive('limitProviderRequests')->once()->with(2)->andReturnNull();
        $gemini->shouldNotReceive('analyzeRoom');
        $gemini->shouldReceive('furnishRoom')->once()->andReturn(['bytes'=>'ai-image','mime_type'=>'image/png']);
        $gemini->shouldNotReceive('verifyGeneratedRoom');
        $raster->shouldReceive('pixelGuard')->once()->andReturn(['supported'=>true,'passed'=>false]);
        app(RoomPreviewV2Service::class)->process($generation->id);
        $this->assertSame('fallback',$generation->fresh()->status);
        $this->assertSame('local-image',Storage::disk('local')->get($generation->fresh()->placement_json['artifact_path']));
    }

    public function test_blocked_placement_never_generates_or_composites_furniture(): void
    {
        [, $generation, $gemini, $raster, $placement] = $this->pipeline();
        $placement->shouldReceive('forProduct')->once()->andReturn(['renderable' => false, 'reason' => 'No reliable doorway opening was detected.']);
        $gemini->shouldNotReceive('furnishRoom', 'analyzeRoom');
        $raster->shouldNotReceive('composite');
        app(RoomPreviewV2Service::class)->process($generation->id);
        $this->assertSame('no_safe_placement', $generation->fresh()->failure_code);
        $this->assertNull($generation->fresh()->placement_json['artifact_path']);
        $this->assertStringContainsString('No reliable doorway', $generation->fresh()->message);
    }

    public function test_internal_processing_budget_is_not_reported_as_google_quota(): void
    {
        [, $generation, $gemini, , $placement] = $this->pipeline();
        $placement->shouldReceive('forProduct')->once()->andReturn([
            'renderable' => true, 'score' => 97.8, 'confidence_score' => 62,
            'max_width_percent' => 34.6, 'x_percent' => 65, 'y_percent' => 80,
        ]);
        $gemini->shouldNotReceive('analyzeRoom', 'verifyGeneratedRoom');
        $gemini->shouldReceive('furnishRoom')->once()->andThrow(new AiRequestBudgetExceeded);
        app(RoomPreviewV2Service::class)->process($generation->id);
        $this->assertSame('provider_call_budget_exhausted', $generation->fresh()->failure_code);
        $this->assertSame('medium', $generation->fresh()->placement_json['confidence_label']);
        $this->assertStringNotContainsString('Gemini has reached', $generation->fresh()->message);
        $this->assertSame('fallback', $generation->fresh()->status);
    }

    public function test_busy_shared_analysis_does_not_make_duplicate_provider_requests(): void
    {
        [$room, $generation, $gemini] = $this->pipeline();
        $room->update(['analysis_status' => 'fallback', 'analysis' => ['gemini_used' => false]]);
        $gemini->shouldNotReceive('analyzeRoom', 'furnishRoom');
        $lock = Cache::lock('room-analysis:'.$room->image_sha256.':'.config('roompreview.pipeline_version'), 240);
        $this->assertTrue($lock->get());
        try {
            app(RoomPreviewV2Service::class)->process($generation->id);
            $this->assertSame('analysis_in_progress', $generation->fresh()->failure_code);
        } finally {
            $lock->release();
        }
    }

    public function test_preview_retries_never_purchase_another_room_analysis(): void
    {
        [$room, $generation, $gemini] = $this->pipeline();
        $room->update(['analysis_status' => 'fallback', 'analysis' => ['gemini_used' => false]]);
        $gemini->shouldNotReceive('analyzeRoom');
        $gemini->shouldNotReceive('furnishRoom');
        $service = app(RoomPreviewV2Service::class);
        $service->process($generation->id);
        $generation->refresh()->update(['status' => 'queued', 'metrics_json' => null]);
        $service->process($generation->id);
        $this->assertSame('analysis_unavailable', $generation->fresh()->failure_code);
        $this->assertDatabaseCount('room_preview_analyses', 0);
    }

    public function test_cancelled_worker_cannot_recreate_an_artifact_after_consent_revocation(): void
    {
        [$room, $generation, $gemini] = $this->pipeline();
        $gemini->shouldReceive('furnishRoom')->once()->andReturnUsing(function () use ($room, $generation) {
            $room->update(['gemini_consent_at' => null]);
            $generation->update(['status' => 'cancelled']);
            throw new ConnectionException('Test timeout');
        });
        app(RoomPreviewV2Service::class)->process($generation->id);
        $this->assertSame('cancelled', $generation->fresh()->status);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_a_stale_queue_job_does_not_process_a_newer_preview_request(): void
    {
        [, $generation, $gemini, $raster] = $this->pipeline();
        $gemini->shouldNotReceive('furnishRoom', 'analyzeRoom');
        $raster->shouldNotReceive('normalize');
        app(RoomPreviewV2Service::class)->process($generation->id, 'old-cache-key');
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertEmpty(Storage::disk('local')->allFiles());
        (new \App\Jobs\GenerateRoomPreviewV2($generation->id, 'old-cache-key'))->failed(new \RuntimeException('Old job failed'));
        $this->assertSame('queued', $generation->fresh()->status);
    }

    public function test_completed_upload_analysis_populates_shared_geometry_without_private_artifact_paths(): void
    {
        [$room, , $gemini, , , $photos] = $this->pipeline();
        $room->update(['analysis_status' => 'queued']);
        $gemini->shouldReceive('analyzeRoom')->once()->andReturn([
            'gemini_used' => true, 'placement_profile_version' => 2, 'analyzed_furniture_type' => 'all catalog furniture',
            'generated_previews' => ['1' => ['path' => 'rooms/private-preview.png']],
        ]);
        (new AnalyzeRoomImage($room->id, 'all catalog furniture'))->handle($gemini, $photos, app(PrivacyAudit::class), app(AiTelemetry::class));
        $cache = RoomPreviewAnalysis::firstOrFail();
        $this->assertSame($room->image_sha256, $cache->image_sha256);
        $this->assertSame('completed', $cache->status);
        $this->assertArrayNotHasKey('generated_previews', $cache->analysis);
    }

    public function test_enhanced_image_prompt_includes_only_numeric_server_geometry(): void
    {
        Storage::fake('local'); Storage::fake('public');
        config(['services.gemini.key' => 'test-key', 'services.gemini.image_model' => 'test-image-model']);
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        Storage::disk('local')->put('rooms/source.png', $png);
        Storage::disk('public')->put('products/set.png', $png);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]]]]]]])]);
        $product = new FurnitureProduct(['name' => 'Catalog sala set', 'category' => 'Sala Set', 'image_path' => 'products/set.png']);
        app(GeminiService::class)->furnishRoom('rooms/source.png', $product, true, 1, 0, [
            'renderable' => true, 'x_percent' => 42, 'y_percent' => 80, 'max_width_percent' => 30,
            'bounds_percent' => [27, 40, 57, 80], 'reason' => 'UNTRUSTED_MARKER',
        ]);
        Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['text'], '"x_percent":42')
            && str_contains($request['contents'][0]['parts'][0]['text'], '"bounds_percent":[27,40,57,80]')
            && ! str_contains($request['contents'][0]['parts'][0]['text'], 'UNTRUSTED_MARKER'));
    }
}
