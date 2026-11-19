<?php

namespace Tests\Feature;

use App\Jobs\{AnalyzeRoomImage, GenerateRoomPreviewV2};
use App\Models\{FurnitureProduct, RoomImage, RoomPreviewGeneration, User};
use App\Services\{DoorwayGeometry, FurniturePlacementService, GeminiClient, GeminiService, RoomPhotoService, RoomPreviewIdentity, RoomPreviewRasterService, RoomPreviewV2Service};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Cache, Context, DB, Http, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomaticPreviewReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); Storage::fake('public');
        config(['services.gemini.key' => 'fake-only', 'services.gemini.model' => 'primary',
            'services.gemini.fallback_model' => 'secondary', 'services.gemini.image_model' => 'test-image-model',
            'roompreview.use_v2_pipeline' => true, 'roompreview.rollout_percent' => 100]);
    }

    private function png(bool $product = false, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($product ? 120 : 800, $product ? 240 : 600);
        imagealphablending($image, false); imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 218, 207, 190, $transparent ? 127 : 0));
        if (! $transparent) {
            if ($product) {
                imagefilledrectangle($image, 0, 0, 119, 239, 0x70402a);
                imagefilledrectangle($image, 30, 30, 89, 100, 0xffffff);
            } else {
                imagefilledrectangle($image, 0, 356, 799, 599, 0x9b7452);
                for ($x = 0; $x < 800; $x += 40) imageline($image, $x, 0, $x, 599, 0x443023);
                for ($y = 0; $y < 600; $y += 40) imageline($image, 0, $y, 799, $y, 0x443023);
            }
        }
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return $bytes;
    }

    private function scene(): array
    {
        $user = User::factory()->create(['role' => 'customer']);
        $analysis = ['gemini_used' => true, 'analyzed_furniture_type' => 'all catalog furniture', 'placement_profile_version' => 2,
            'floor_polygon' => [[0,60],[100,60],[100,100],[0,100]], 'scene_confidence' => 'high'];
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/reliability.png', 'analysis' => $analysis,
            'analysis_status' => 'completed', 'analysis_requested_at' => now()->subMinutes(2), 'gemini_consent_at' => now()]);
        Storage::disk('local')->put($room->image_path, $this->png());
        $product = FurnitureProduct::create(['name' => 'Reliable chair', 'slug' => 'reliable-chair', 'category' => 'Chair',
            'description' => 'Local test fixture', 'wood_type' => 'Wood', 'varnish_finish' => 'Natural', 'style' => 'Modern',
            'price' => 1000, 'status' => 'active', 'width_cm' => 60, 'depth_cm' => 60, 'height_cm' => 100, 'image_path' => 'products/reliability.png']);
        Storage::disk('public')->put($product->image_path, $this->png(true));
        $this->actingAs($user);
        return [$room->fresh(), $product->fresh()];
    }

    public function test_shared_quad_fixtures_reject_invalid_corners_without_reordering_or_clamping(): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/doorway-fit-quads.json')), true) as $case) {
            $quad = DoorwayGeometry::quad($case['quad']);
            $this->assertSame($case['valid'], $quad !== null, $case['name']);
            if ($case['valid'] && max(array_merge(...$case['quad'])) > 1) $this->assertEquals($case['quad'], $quad);
        }
    }

    public static function openingContainers(): array { return [['vision'],['openings'],['candidate_zones']]; }

    #[DataProvider('openingContainers')]
    public function test_fit_corners_survive_the_real_provider_sanitizer_and_saved_analysis(string $container): void
    {
        [$room, $product] = $this->scene();
        $product->update(['category' => 'Door']);
        $quad = [[12,12],[33,18],[40,91],[17,87]];
        $geometry = ['image_quality' => ['usable' => true, 'issues' => []], 'floor_polygon' => [], 'walls' => [],
            'openings' => [['type' => 'door', 'bbox' => [.12,.12,.40,.91], 'is_open' => true, 'door_fit_quad_percent' => $quad]],
            'horizon_y' => .3, 'blocked_zones' => [], 'existing_furniture' => [], 'scale_clues' => [], 'lighting' => [], 'confidence' => .9];
        $decoded = ['vision_geometry' => $geometry, 'candidate_zones' => [[
            'surface' => 'floor', 'anchor_x_percent' => 55, 'anchor_y_percent' => 88, 'max_width_percent' => 45,
            'suitable_types' => ['chair', 'living set', 'dining', 'table', 'seating'],
            'clearance_score' => 90, 'occlusion_score' => 5, 'confidence' => 'high', 'reason' => 'Clear floor zone.',
        ], [
            'surface' => 'wall-floor', 'anchor_x_percent' => 65, 'anchor_y_percent' => 90, 'max_width_percent' => 50,
            'suitable_types' => ['storage', 'bed', 'cabinet', 'wardrobe'],
            'clearance_score' => 87, 'occlusion_score' => 6, 'confidence' => 'high', 'reason' => 'Clear wall-floor zone.',
        ]]];
        $path = 'vision_geometry.openings.0.door_fit_quad_percent';
        if ($container !== 'vision') {
            $decoded['vision_geometry']['openings'] = [];
            $decoded[$container] = $container === 'openings'
                ? [['label'=>'Doorway frame','door_fit_quad_percent'=>$quad]]
                : array_merge($decoded[$container], [['surface'=>'opening','anchor_x_percent'=>26,'anchor_y_percent'=>91,'max_width_percent'=>28,'suitable_types'=>['Door'],'door_fit_quad_percent'=>$quad]]);
            $path = $container === 'candidate_zones' ? 'candidate_zones.1.door_fit_quad_percent' : $container.'.0.door_fit_quad_percent';
        }
        Http::fake(['*' => Http::response($this->text($decoded))]);
        $room->update(['analysis_status' => 'queued']);
        $job = new AnalyzeRoomImage($room->id, 'all catalog furniture');
        app()->call([$job, 'handle']);
        $room->refresh();
        $persistedQuad = $container === 'candidate_zones'
            ? data_get(collect($room->analysis['candidate_zones'] ?? [])->first(fn ($zone) => is_array($zone) && ($zone['surface'] ?? null) === 'opening'), 'door_fit_quad_percent')
            : data_get($room->analysis, $path);
        $this->assertEquals($quad, $persistedQuad);
        $placement = app(FurniturePlacementService::class)->forProduct($room->analysis, $product);
        $this->assertTrue($placement['renderable']);
        $this->assertEquals($quad, $placement['quad_percent']);
        $this->get(route('room.visualize', [$room, 'product' => $product->id]))->assertOk()->assertSee('data-placement-renderable="true"', false);
        $preference=auth()->user()->preference()->create(['furniture_type'=>'Door','budget_min'=>1000,'budget_max'=>10000]);
        $recommendation=app(\App\Services\RecommendationService::class)->generate(auth()->user(),$preference,$room,'Door');
        $response=$this->get(route('recommendations.show',$recommendation))->assertOk()->assertSee('data-placement-renderable="true"',false);
        $dom=new \DOMDocument(); @$dom->loadHTML($response->getContent());
        $frame=(new \DOMXPath($dom))->query('//*[@data-room-preview]')->item(0);
        $this->assertNotNull($frame);
        $this->assertEquals($quad,json_decode($frame->getAttribute('data-initial-quad'),true));
        // Simulate a redelivery after sending but before a worker records completion.
        $room->update(['analysis_status' => 'processing']);
        DB::table('room_preview_analyses')->delete();
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('ai_action_attempts')->where('operation', 'room_analysis')->count());
    }

    public function test_door_detection_without_fit_corners_and_windows_do_not_invent_a_quad(): void
    {
        [$room, $product] = $this->scene(); $product->category = 'Door';
        foreach (['door', 'window'] as $type) {
            $analysis = ['vision_geometry' => ['openings' => [['type' => $type, 'bbox' => [.1,.1,.3,.9]]]]];
            $placement = app(FurniturePlacementService::class)->forProduct($analysis, $product);
            $this->assertFalse($placement['renderable']);
            $this->assertNull($placement['quad_percent']);
            if ($type === 'door') $this->assertStringContainsString('fit corners', $placement['reason']);
        }
        Http::assertNothingSent();
    }

    public static function enhancementCases(): array
    {
        return [['success',2], ['guard-failed',1], ['unchanged-candidate',1], ['generation-403',1], ['generation-429',1], ['generation-503',1], ['generation-malformed',1], ['timeout',1], ['verification-403',2], ['verification-429',2], ['verification-503',2], ['verification-malformed',2],
            ['budget-after-generation',1], ['disabled',0], ['transparent-asset',0], ['blocked',0], ['no-consent',0], ['no-gd',0]];
    }

    #[DataProvider('enhancementCases')]
    public function test_v2_limits_actual_outbound_attempts_and_retains_local_fallback(string $mode, int $expected): void
    {
        [$room, $product] = $this->scene();
        $raster = app(RoomPreviewRasterService::class);
        $placement = app(FurniturePlacementService::class)->forProduct($room->analysis, $product);
        $local = $raster->composite(Storage::disk('local')->get($room->image_path), $product, $placement);
        $this->assertNotNull($local);
        if ($mode === 'disabled') config(['roompreview.use_v2_pipeline' => false]);
        if ($mode === 'transparent-asset') Storage::disk('public')->put($product->image_path, $this->png(true, true));
        if ($mode === 'blocked') $room->update(['analysis' => array_merge($room->analysis, ['obstacles' => [['polygon' => [[0,0],[100,0],[100,100],[0,100]]]]])]);
        if ($mode === 'no-consent') $room->update(['gemini_consent_at' => null]);
        if ($mode === 'no-gd') $this->partialMock(RoomPreviewRasterService::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(false));
        if ($mode === 'budget-after-generation') config(['services.gemini.daily_request_limit' => 1]);
        $generation = RoomPreviewGeneration::create(['room_image_id' => $room->id, 'furniture_product_id' => $product->id,
            'status' => 'queued', 'attempt_token' => (string) \Illuminate\Support\Str::uuid(), 'cache_key' => RoomPreviewIdentity::key($room->fresh(), $product)]);
        Http::fake(function ($request) use ($mode, $local, $generation) {
            if (str_contains($request->url(), 'test-image-model')) {
                // A concurrent delivery cannot purchase a second generation.
                app(RoomPreviewV2Service::class)->process($generation->id, $generation->cache_key, $generation->attempt_token);
                if ($mode === 'timeout') throw new \Illuminate\Http\Client\ConnectionException('fake timeout');
                if ($mode === 'generation-503') return Http::response([], 503);
                if ($mode === 'generation-403') return Http::response([], 403);
                if ($mode === 'generation-429') return Http::response([], 429);
                if ($mode === 'generation-malformed') return Http::response(['invalid' => true]);
                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png',
                    'data' => base64_encode($mode === 'guard-failed' ? $this->png(true) : ($mode === 'unchanged-candidate' ? $this->png() : $local['bytes']))]]]]]]]);
            }
            if ($mode === 'verification-403') return Http::response([], 403);
            if ($mode === 'verification-429') return Http::response([], 429);
            if ($mode === 'verification-503') return Http::response([], 503);
            if ($mode === 'verification-malformed') return Http::response(['invalid' => true]);
            return Http::response($this->text(['scores' => array_fill_keys(['scale','placement','catalog_fidelity','room_preservation','lighting'], .9), 'single_item_only' => true, 'catalog_item_only' => true]));
        });
        app(RoomPreviewV2Service::class)->process($generation->id, $generation->cache_key, $generation->attempt_token);
        // Laravel's fake recorder omits connection exceptions; the durable before-send
        // claim and aggregate reservation still count that submitted attempt.
        Http::assertSentCount($mode === 'timeout' ? 0 : $expected);
        $this->assertSame($expected, DB::table('ai_action_attempts')->count());
        $generation->refresh();
        $this->assertSame($mode === 'success' ? 'completed' : 'fallback', $generation->status);
        $this->assertSame($expected, $generation->metrics_json['provider_calls']);
        if (! in_array($mode, ['blocked','transparent-asset','no-gd'], true)) {
            $this->assertTrue(Storage::disk('local')->exists($generation->placement_json['artifact_path']));
        }
        app(RoomPreviewV2Service::class)->process($generation->id, $generation->cache_key, $generation->attempt_token);
        Http::assertSentCount($mode === 'timeout' ? 0 : $expected);
    }

    public function test_duplicate_tabs_and_retries_preserve_accounting_and_input_changes_invalidate_artifacts(): void
    {
        [$room, $product] = $this->scene(); Bus::fake();
        $url = route('room.preview-v2.store', $room);
        $this->postJson($url, ['furniture_id' => $product->id])->assertAccepted();
        $generation = RoomPreviewGeneration::firstOrFail(); $token = $generation->attempt_token;
        $this->postJson($url, ['furniture_id' => $product->id, 'retry' => true])->assertAccepted();
        Bus::assertDispatchedTimes(GenerateRoomPreviewV2::class, 1);
        $this->assertSame($token, $generation->fresh()->attempt_token);
        $generation->update(['status' => 'fallback', 'attempts' => 3, 'metrics_json' => ['provider_calls' => 2], 'completed_at' => now()]);
        $this->postJson($url, ['furniture_id' => $product->id, 'retry' => true])->assertStatus(429);
        $generation->update(['status' => 'failed']);
        $this->postJson($url, ['furniture_id' => $product->id])->assertStatus(429);
        $generation->update(['status' => 'fallback']);
        Storage::disk('public')->put($product->image_path, $this->png(true, true));
        $this->assertNotSame($generation->cache_key, RoomPreviewIdentity::key($room, $product));
        $this->postJson($url, ['furniture_id' => $product->id])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson($url, ['furniture_id' => $product->id, 'retry' => true])->assertAccepted();
        $generation->refresh();
        $this->assertSame(3, $generation->attempts); $this->assertSame(2, $generation->metrics_json['provider_calls']);
        $this->assertNotSame($token, $generation->attempt_token);
        $oldKey = $generation->cache_key;
        $room->update(['analysis' => array_merge($room->analysis, ['scene_confidence' => 'low'])]);
        $this->assertNotSame($oldKey, RoomPreviewIdentity::key($room, $product));
        Http::assertNothingSent();
    }

    public function test_status_and_page_reads_are_free_and_status_does_not_persist_recovery(): void
    {
        [$room, $product] = $this->scene(); Bus::fake();
        $room->update(['analysis_status' => 'processing', 'analysis_requested_at' => now()->subHour()]);
        $before = $room->fresh()->getAttributes();
        $this->getJson(route('room.analysis-status', $room))->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame($before, $room->fresh()->getAttributes());
        foreach ([1,2] as $_) $this->get(route('room.visualize', [$room, 'product' => $product->id]))->assertOk();
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    private function text(array $data): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]]];
    }

    public function test_consented_upload_cache_hit_and_explicit_reanalysis_have_bounded_real_attempts(): void
    {
        [$room] = $this->scene();
        auth()->user()->preference()->create(['furniture_type' => 'Chair', 'budget_min' => 1000, 'budget_max' => 10000]);
        $geometry = ['image_quality' => ['usable' => true, 'issues' => []], 'floor_polygon' => [], 'walls' => [], 'openings' => [],
            'horizon_y' => .3, 'blocked_zones' => [], 'existing_furniture' => [], 'scale_clues' => [], 'lighting' => [], 'confidence' => .9];
        Http::fake(['*' => Http::response($this->text(['vision_geometry' => $geometry, 'candidate_zones' => [[
            'surface' => 'floor', 'anchor_x_percent' => 50, 'anchor_y_percent' => 86, 'max_width_percent' => 60,
            'suitable_types' => ['chair', 'living set', 'dining', 'table', 'seating'],
            'clearance_score' => 80, 'occlusion_score' => 10, 'confidence' => 'medium', 'reason' => 'Clear visible area.',
        ], [
            'surface' => 'wall-floor', 'anchor_x_percent' => 55, 'anchor_y_percent' => 88, 'max_width_percent' => 56,
            'suitable_types' => ['storage', 'bed', 'cabinet', 'wardrobe'],
            'clearance_score' => 80, 'occlusion_score' => 10, 'confidence' => 'medium', 'reason' => 'Clear wall-floor area.',
        ]]]))]);
        $upload = fn () => $this->postJson(route('room.store'), [
            'room_image' => \Illuminate\Http\UploadedFile::fake()->createWithContent('local-fixture.png', $this->png()),
            'gemini_consent' => true, 'ai_consent_provider' => 'gemini',
        ])->assertCreated();
        $first = $upload()->json('room_id');
        $upload(); Http::assertSentCount(1);
        $uploaded = RoomImage::findOrFail($first);
        $this->assertSame('completed', $uploaded->analysis_status);
        $this->travel(61)->seconds();
        $this->postJson(route('room.reanalyze', $uploaded))->assertOk();
        $this->postJson(route('room.reanalyze', $uploaded))->assertStatus(429);
        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('ai_action_attempts')->where('operation', 'room_analysis')->count());
    }

    public static function legacyChecks(): array { return [[200],[403],[429],[503],[0]]; }

    public function test_lost_metrics_cannot_reset_the_approved_cumulative_preview_request_cap(): void
    {
        [$room,$product]=$this->scene(); Http::fake();
        $generation=RoomPreviewGeneration::create(['room_image_id'=>$room->id,'furniture_product_id'=>$product->id,
            'status'=>'queued','cache_key'=>RoomPreviewIdentity::key($room,$product),'attempt_token'=>'new-attempt','metrics_json'=>null]);
        $cap=(int) config('roompreview.max_provider_calls');
        for($i=0;$i<$cap;$i++) DB::table('ai_action_attempts')->insert([
            'attempt_key'=>hash('sha256','prior-fixture-'.$i),'action_key'=>'preview-v2:'.$generation->id.':prior-'.$i,
            'operation'=>'furnished_preview','created_at'=>now()->subMinutes(2),
        ]);
        app(RoomPreviewV2Service::class)->process($generation->id,$generation->cache_key,'new-attempt');
        $generation->refresh(); Http::assertNothingSent();
        $this->assertSame('fallback',$generation->status);
        $this->assertSame('provider_call_budget_exhausted',$generation->failure_code);
        $this->assertSame($cap,$generation->metrics_json['provider_calls']);
        $this->assertTrue(Storage::disk('local')->exists($generation->placement_json['artifact_path']));
        $this->assertDatabaseCount('ai_action_attempts',$cap);
    }

    public function test_cumulative_cap_is_enforced_at_the_outbound_boundary_across_fresh_clients(): void
    {
        [$room,$product]=$this->scene(); Http::fake(['*'=>Http::response($this->text(['valid'=>true]))]);
        $generation=RoomPreviewGeneration::create(['room_image_id'=>$room->id,'furniture_product_id'=>$product->id,
            'status'=>'processing','cache_key'=>RoomPreviewIdentity::key($room,$product),'attempt_token'=>'current-token']);
        $cap=(int) config('roompreview.max_provider_calls');
        for($i=0;$i<$cap-1;$i++) DB::table('ai_action_attempts')->insert([
            'attempt_key'=>hash('sha256','old-boundary-fixture-'.$i),'action_key'=>'preview-v2:'.$generation->id.':prior-'.$i,
            'operation'=>'furnished_preview','created_at'=>now()->subMinutes(2),
        ]);
        Context::addHidden('ai_action_key','preview-v2:'.$generation->id.':current-token');
        Context::addHidden('ai_photo_transfer',['room_id'=>$room->id,'user_id'=>$room->user_id,'purpose'=>'room_preview_verification']);
        try {
            app(GeminiClient::class)->generateText([['text'=>'Last allowed fake request']],operation:'furnished_preview',retryAttempts:0,modelLimit:1);
            try {
                app(GeminiClient::class)->generateText([['text'=>'Must be blocked']],operation:'room_preview_verification');
                $this->fail('A new client must not reset the cumulative cap.');
            } catch (\App\Services\AiRequestBudgetExceeded) { $this->assertTrue(true); }
        } finally { Context::forgetHidden('ai_action_key'); Context::forgetHidden('ai_photo_transfer'); }
        Http::assertSentCount(1); $this->assertDatabaseCount('ai_action_attempts',$cap);
    }

    public function test_outbound_boundary_rechecks_stored_consent_before_reserving_or_sending(): void
    {
        [$room] = $this->scene(); Http::fake();
        Context::addHidden('ai_action_key', 'revoked-consent-fixture');
        Context::addHidden('ai_photo_transfer', ['room_id'=>$room->id,'user_id'=>$room->user_id,'purpose'=>'room_analysis']);
        $room->update(['gemini_consent_at'=>null]);
        try {
            app(GeminiClient::class)->generateText([['text'=>'Local fixture'],['inline_data'=>['mime_type'=>'image/png','data'=>base64_encode($this->png())]]], operation:'room_analysis');
            $this->fail('A revoked photo must never be sent.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('photo_consent_required', $exception->getMessage());
        } finally { Context::forgetHidden('ai_action_key'); Context::forgetHidden('ai_photo_transfer'); }
        Http::assertNothingSent(); $this->assertDatabaseCount('ai_action_attempts',0);
        $this->assertDatabaseCount('ai_usage_buckets',0);
    }

    public function test_old_artifacts_and_queue_tokens_are_rejected_after_inputs_or_attempts_change(): void
    {
        [$room,$product] = $this->scene();
        $path='rooms/artifact-fixture.png'; Storage::disk('local')->put($path,$this->png());
        $generation=RoomPreviewGeneration::create(['room_image_id'=>$room->id,'furniture_product_id'=>$product->id,
            'status'=>'completed','cache_key'=>RoomPreviewIdentity::key($room,$product),'attempt_token'=>'old-token','placement_json'=>['artifact_path'=>$path]]);
        $statusUrl=route('room.preview-v2.status',[$room,$generation]);
        $artifact=$this->getJson($statusUrl)->assertOk()->json('preview_url');
        $this->assertNotNull($artifact); $this->get($artifact)->assertOk();
        $analysis=$room->analysis;
        $room->update(['analysis'=>array_merge($analysis,['scene_confidence'=>'low'])]);
        $this->get($artifact)->assertNotFound(); $this->getJson($statusUrl)->assertJsonPath('preview_url',null);
        $room->update(['analysis'=>$analysis]);
        Storage::disk('public')->put($product->image_path,$this->png(true,true));
        $this->get($artifact)->assertNotFound();
        $generation->update(['status'=>'queued','attempt_token'=>'new-token']);
        app(RoomPreviewV2Service::class)->process($generation->id,$generation->cache_key,'old-token');
        (new GenerateRoomPreviewV2($generation->id,$generation->cache_key,'old-token'))->failed(new \RuntimeException('stale delivery'));
        $this->assertSame('queued',$generation->fresh()->status);
        app(RoomPreviewV2Service::class)->process($generation->id,$generation->cache_key,'new-token');
        $this->assertSame('cancelled',$generation->fresh()->status);
        Http::assertNothingSent();
    }

    #[DataProvider('legacyChecks')]
    public function test_explicit_legacy_checks_and_cache_misses_never_resubmit_an_attempt(int $status): void
    {
        [$room, $product] = $this->scene();
        Http::fake(['*' => Http::response($status === 200 ? $this->text(['verdict' => 'good', 'realism_score' => .9]) : ($status === 0 ? ['invalid' => true] : []), $status ?: 200)]);
        $payload = ['preview' => base64_encode($this->png()), 'placement' => ['mode' => 'floor', 'x' => .5, 'y' => .8, 'width' => .3, 'height' => .2, 'scale' => 1]];
        $url = route('room.preview-verify', [$room,$product->id]);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('valid', $status === 200);
        $this->postJson($url, $payload)->assertOk();
        Cache::flush(); // A lost cache cannot erase the durable outbound attempt reservation.
        $this->postJson($url, $payload)->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('ai_action_attempts')->where('operation', 'room_preview_verification')->count());
        $this->assertDatabaseHas('ai_usage_buckets', ['bucket' => 'day:'.now('UTC')->format('Y-m-d'), 'requests' => 1]);
    }
}
