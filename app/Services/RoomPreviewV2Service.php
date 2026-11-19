<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\RoomPreviewAnalysis;
use App\Models\RoomPreviewGeneration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RoomPreviewV2Service
{
    // Include local rendering changes so newly requested artifacts do not reuse old shadows.
    public const PLACEMENT_VERSION = 'photo-2d-placement-v6-visible-door-corners';

    private int $providerCallStart = 0;

    private int $previousProviderCalls = 0;
    private int $generationId = 0;

    public function __construct(
        private GeminiService $gemini,
        private RoomPhotoService $photos,
        private FurniturePlacementService $placement,
        private RoomPreviewRasterService $raster,
        private AiTelemetry $telemetry,
        private PrivacyAudit $audit,
    ) {}

    public function process(int $generationId, ?string $expectedCacheKey = null, ?string $expectedToken = null): void
    {
        $started = hrtime(true);
        $this->generationId = $generationId;
        $this->providerCallStart = $this->gemini->providerCallCount();
        $generation = RoomPreviewGeneration::find($generationId);
        if (! $generation || $generation->status !== 'queued'
            || ($expectedCacheKey !== null && $generation->cache_key !== $expectedCacheKey)
            || ($expectedToken !== null && $generation->attempt_token !== $expectedToken)) {
            return;
        }
        $room = RoomImage::find($generation->room_image_id);
        $product = FurnitureProduct::find($generation->furniture_product_id);
        if (! $room || ! $product || $product->status !== 'active') {
            $this->finish($generation, 'failed', 'catalog_item_unavailable', 'The selected catalog item is inactive or unavailable.');

            return;
        }
        if (! $this->photos->exists($room->image_path)) {
            $this->finish($generation, 'failed', 'room_photo_missing', 'The room photo is no longer available.');

            return;
        }
        if ($generation->attempt_token && ! hash_equals($generation->cache_key, RoomPreviewIdentity::key($room, $product))) {
            $this->finish($generation, 'cancelled', 'preview_inputs_changed', 'The photo analysis or catalog item changed. Use the current local preview.');
            return;
        }

        if (RoomPreviewGeneration::whereKey($generation->id)->where('status', 'queued')
            ->where('cache_key', $generation->cache_key)->where('attempt_token', $generation->attempt_token)
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'failure_code' => null, 'message' => null]) !== 1) return;
        $generation->refresh();
        $this->previousProviderCalls = max((int) data_get($generation->metrics_json, 'provider_calls', 0),
            DB::table('ai_action_attempts')->where('action_key', 'like', 'preview-v2:'.$generation->id.':%')->count());
        $this->gemini->limitProviderRequests(max(0, min(2, (int) config('roompreview.max_provider_calls', 8) - $this->previousProviderCalls)));
        $providerCalls = 0;
        $tempPath = null;
        $previousActor = Context::getHidden('ai_user_id');
        $previousAction = Context::getHidden('ai_action_key');

        try {
            Context::addHidden('ai_user_id', $room->user_id);
            Context::addHidden('ai_action_key', 'preview-v2:'.$generation->id.':'.($generation->attempt_token ?? $generation->cache_key));
            $source = $this->photos->get($room->image_path);
            if (strlen($source) > (int) config('roompreview.max_upload_bytes')) {
                throw new \RuntimeException('image_too_large');
            }
            $normalized = $this->raster->normalize($source);
            $temporaryExtension = match ($normalized['mime']) {
                'image/jpeg', 'image/jpg' => 'jpg', 'image/webp' => 'webp', default => 'png'
            };
            $tempPath = 'rooms/'.$room->user_id.'/tmp/v2-'.Str::uuid().'.'.$temporaryExtension;
            $this->photos->storeGenerated($tempPath, $normalized['bytes']);

            $room->refresh();
            [$analysis, $analysisFailure] = $this->analysisFor($room, $room->image_sha256 ?: hash('sha256', $source), $tempPath);

            // Preview width follows the visible clear zone; catalogue dimensions remain reference data.
            $roomWidth = null;
            $roomDepth = null;
            $placement = $this->placement->forProduct($analysis, $product, $roomWidth, $roomDepth);
            $confidence = max(0.0, min(1.0, ((float) ($placement['confidence_score'] ?? $placement['score'] ?? 0)) / 100));
            $label = $confidence >= 0.75 ? 'high' : ($confidence >= 0.5 ? 'medium' : 'rough_estimate');
            $quality = $normalized['quality'];

            if (is_array($quality) && ! ($quality['usable'] ?? false)) {
                $this->savePlacementFallback($generation, $placement, $confidence, $label, 'photo_quality_low', $started, $providerCalls);

                return;
            }

            if (($placement['renderable'] ?? true) === false) {
                $this->savePlacementFallback($generation, $placement, 0, 'rough_estimate', 'no_safe_placement', $started, $providerCalls);

                return;
            }

            $artifact = null;
            $localArtifact = $this->raster->composite($normalized['bytes'], $product, $placement);
            if (is_array($localArtifact['bounds'] ?? null)) {
                [$left, $top, $right, $bottom] = $localArtifact['bounds'];
                $placement['bounds_percent'] = [100 * $left / $normalized['width'], 100 * $top / $normalized['height'], 100 * $right / $normalized['width'], 100 * $bottom / $normalized['height']];
            }
            $verifiedByAi = false;
            $aiFailureCode = $analysisFailure;
            $verification = null;
            $guard = null;
            if (! $localArtifact) {
                $this->savePlacementFallback($generation, $placement, $confidence, $label, $this->raster->available() ? 'preview_composite_unavailable' : 'gd_extension_missing', $started, $providerCalls);
                return;
            }
            if (! $analysisFailure && config('roompreview.use_v2_pipeline') && config('services.gemini.image_enabled') && AiProvider::name() === 'gemini' && $room->gemini_consent_at && $this->gemini->isConfigured()) {
                for ($attempt = 0; $attempt < min(1, (int) config('roompreview.max_generation_attempts', 1)); $attempt++) {
                    if (! $this->hasConsent($room->id)) {
                        break;
                    }
                    \Illuminate\Support\Facades\Context::addHidden('ai_photo_transfer', ['room_id' => $room->id, 'purpose' => 'room_preview_v2_generation', 'user_id' => $room->user_id]);
                    try {
                        $generated = $this->gemini->furnishRoom($tempPath, $product, true, 1, 0, $placement);
                    } catch (Throwable $exception) {
                        $httpStatus = $exception instanceof RequestException ? $exception->response->status() : null;
                        $aiFailureCode = $this->failureCode($exception, 'ai_generation_unavailable');
                        Log::warning('AI preview generation unavailable; using local estimate', [
                            'generation_id' => $generation->id,
                            'exception' => get_class($exception),
                            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                        ]);
                        break;
                    }
                    $providerCalls += 1;
                    if (! is_string($generated['bytes'] ?? null) || $generated['bytes'] === '') {
                        continue;
                    }
                    if (! $this->hasConsent($room->id)) {
                        continue;
                    }
                    if ($generation->attempt_token && ! hash_equals($generation->cache_key, RoomPreviewIdentity::key($room->fresh(), $product->fresh()))) {
                        $aiFailureCode = 'preview_inputs_changed';
                        break;
                    }
                    $bounds = $localArtifact['guard_bounds'] ?? $localArtifact['bounds'] ?? $this->expectedBounds($placement, $product, $normalized['width'], $normalized['height']);
                    $guard = $this->raster->pixelGuard($normalized['bytes'], $generated['bytes'], $bounds);
                    if (! ($guard['supported'] ?? false) || ! ($guard['passed'] ?? false)) {
                        $aiFailureCode = 'generated_image_failed_local_checks';
                        break;
                    }
                    \Illuminate\Support\Facades\Context::addHidden('ai_photo_transfer', ['room_id' => $room->id, 'purpose' => 'room_preview_v2_verification', 'user_id' => $room->user_id]);
                    try {
                        $verification = $this->gemini->verifyGeneratedRoom($tempPath, $generated['bytes'], $product, 1);
                    } catch (Throwable $exception) {
                        $httpStatus = $exception instanceof RequestException ? $exception->response->status() : null;
                        $aiFailureCode = $this->failureCode($exception, 'ai_verification_unavailable');
                        Log::warning('AI preview verification unavailable; using local estimate', [
                            'generation_id' => $generation->id,
                            'exception' => get_class($exception),
                            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                        ]);
                        break;
                    }
                    $providerCalls += 1;
                    if (($verification['passed'] ?? false) && ($guard['supported'] ?? false) && ($guard['passed'] ?? false)) {
                        $artifact = ['bytes' => $generated['bytes'], 'mime' => $generated['mime_type'] ?? 'image/png'];
                        $verifiedByAi = true;
                        break;
                    }
                    Log::info('Room preview rejected by server quality gates', [
                        'generation_id' => $generation->id,
                        'attempt' => $attempt + 1,
                        'verification_passed' => $verification['passed'] ?? false,
                        'pixel_guard' => $guard,
                    ]);
                }
            }

            if (! $artifact) {
                $artifact = $localArtifact;
                // Rejected AI checks do not describe the saved local composite.
                $verification = null;
                $guard = null;
            }
            if (! $artifact) {
                $this->savePlacementFallback($generation, $placement, $confidence, $label, $this->raster->available() ? 'preview_composite_unavailable' : 'gd_extension_missing', $started, $providerCalls);

                return;
            }

            $artifactExtension = match ($artifact['mime'] ?? 'image/png') {
                'image/jpeg', 'image/jpg' => 'jpg', 'image/webp' => 'webp', default => 'png'
            };
            $path = 'rooms/'.$room->user_id.'/previews/v2-'.$generation->id.'-'.$generation->cache_key.'.'.$artifactExtension;
            $placementData = [
                'pipeline' => 'v2',
                'placement' => $placement,
                'confidence' => $confidence,
                'confidence_label' => $label,
                'disclaimer' => 'This preview is a visual planning estimate only. It does not confirm exact dimensions, fit, or installation clearance. Measure the room and product before purchase.',
                'artifact_path' => $path,
                'verification' => $verification,
                'pixel_guard' => $guard,
                'image_quality' => $quality,
            ];
            DB::transaction(function () use ($room, $generation, $path, $artifact, $placementData, $verifiedByAi, $aiFailureCode, $confidence, $label, $started, $providerCalls, $quality, $verification, $guard): void {
                $currentRoom = RoomImage::whereKey($room->id)->lockForUpdate()->first();
                $currentGeneration = RoomPreviewGeneration::whereKey($generation->id)->lockForUpdate()->first();
                if (! $currentRoom || ! $currentGeneration || $currentGeneration->status === 'cancelled'
                    || $currentGeneration->cache_key !== $generation->cache_key || $currentGeneration->attempt_token !== $generation->attempt_token) {
                    return;
                }
                if ($currentRoom->gemini_consent_at != $room->gemini_consent_at) {
                    throw new \RuntimeException('consent_revoked');
                }
                if ($generation->attempt_token && ! hash_equals($generation->cache_key, RoomPreviewIdentity::key($currentRoom, FurnitureProduct::findOrFail($generation->furniture_product_id)))) {
                    $this->finish($generation, 'cancelled', 'preview_inputs_changed', 'The photo analysis or catalog item changed. Your current local preview remains available.');
                    return;
                }
                $this->photos->storeGenerated($path, $artifact['bytes']);
                $fallbackMessage = match ($aiFailureCode) {
                    'ai_access_denied' => 'Gemini access was denied. A local 2D planning preview is shown; ask the system administrator to check the API key and project permissions before retrying.',
                    'ai_request_limit_reached' => 'Gemini has reached its request limit. A local 2D planning preview is shown; wait a few minutes before retrying.',
                    'provider_call_budget_exhausted' => 'This preview reached FurniCraft’s processing limit. A local 2D planning preview is shown.',
                    'application_ai_budget_exhausted' => 'FurniCraft has reached its configured AI usage limit. A local 2D planning preview is shown.',
                    'analysis_in_progress' => 'Room analysis is already running. A local 2D estimate is shown while it finishes.',
                    'analysis_unavailable' => 'Room analysis is unavailable. A local 2D planning estimate is shown; you can retry the room analysis.',
                    default => 'A local 2D planning preview is ready; verify real measurements.',
                };
                $this->finish($generation, $verifiedByAi ? 'completed' : 'fallback', $aiFailureCode,
                    $verifiedByAi ? 'Verified room preview is ready.' : $fallbackMessage,
                    $placementData, $confidence, $label, $this->metrics($started, $providerCalls, $quality, $verification, $guard));
            });
        } catch (Throwable $exception) {
            if ($exception->getMessage() === 'consent_revoked') {
                $this->finish($generation, 'cancelled', 'consent_revoked', 'AI processing was disabled before the preview could be saved.');

                return;
            }
            $code = preg_match('/^[a-z0-9_]{1,100}$/', $exception->getMessage()) ? $exception->getMessage() : 'preview_pipeline_error';
            Log::warning('Room preview v2 pipeline failed', ['generation_id' => $generation->id, 'failure_code' => $code, 'exception' => $exception::class]);
            $this->finish($generation, 'failed', $code, 'The room preview could not be completed. Please check the photo and try again.', metrics: $this->metrics($started, $providerCalls, null, null, null));
            throw $exception;
        } finally {
            Context::forgetHidden('ai_photo_transfer');
            $previousAction === null ? Context::forgetHidden('ai_action_key') : Context::addHidden('ai_action_key', $previousAction);
            $previousActor === null ? Context::forgetHidden('ai_user_id') : Context::addHidden('ai_user_id', $previousActor);
            $this->gemini->clearProviderRequestLimit();
            if ($tempPath) {
                $this->photos->deleteGenerated($tempPath);
            }
        }
    }

    /** Fit catalog width to the image perspective using reliable known-size clues: s(y)=k*(y-horizon_y). */
    private function hasConsent(int $roomImageId): bool
    {
        $room = RoomImage::find($roomImageId);
        return $room !== null && AiProvider::hasPhotoConsent($room);
    }

    private function failureCode(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof AiAggregateBudgetExceeded) {
            return 'application_ai_budget_exhausted';
        }
        if ($exception instanceof AiRequestBudgetExceeded) {
            return 'provider_call_budget_exhausted';
        }
        $status = $exception instanceof RequestException ? $exception->response->status() : null;

        return match ($status) {
            429 => 'ai_request_limit_reached', 403 => 'ai_access_denied', default => $fallback
        };
    }

    private function analysisFor(RoomImage $room, string $hash, string $path): array
    {
        $analysis = $room->analysis ?? [];
        if (! AiProvider::hasPhotoConsent($room) || ! $this->gemini->isConfigured()) {
            return [$analysis, null];
        }

        // Upload analysis is already photo-only; reuse it instead of analyzing the same photo again.
        if ($room->analysis_status === 'completed' && ($analysis['gemini_used'] ?? false)
            && ($analysis['analyzed_furniture_type'] ?? null) === 'all catalog furniture'
            && (int) ($analysis['placement_profile_version'] ?? 0) >= 2) {
            return [$analysis, null];
        }
        if (in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true)) {
            return [$analysis, 'analysis_in_progress'];
        }

        $version = (string) config('roompreview.pipeline_version', 'v2');
        $lock = Cache::lock('room-analysis:'.$hash.':'.$version, 240);
        if (! $lock->get()) return [$analysis, 'analysis_in_progress'];
        $lock->release();
        $cached = RoomPreviewAnalysis::where('image_sha256', $hash)->where('pipeline_version', $version)
            ->where('status', 'completed')->first();
        if ($cached && is_array($cached->analysis) && ($cached->analysis['gemini_used'] ?? false)) {
            return [$cached->analysis, null];
        }
        // Changing furniture must not purchase another analysis of the same room.
        // The explicit room re-analysis action owns all paid geometry requests.
        return [$analysis, 'analysis_unavailable'];
    }
    private function savePlacementFallback(RoomPreviewGeneration $generation, array $placement, float $confidence, string $label, string $failureCode, int $started, int $calls): void
    {
        $data = [
            'pipeline' => 'v2', 'placement' => $placement, 'confidence' => $confidence, 'confidence_label' => $label,
            'disclaimer' => 'This preview is a visual planning estimate only. It does not confirm exact dimensions, fit, or installation clearance. Measure the room and product before purchase.',
            'artifact_path' => null, 'image_quality' => null,
        ];
        $message = match ($failureCode) {
            'no_safe_placement' => ($placement['reason'] ?? 'No clear furniture placement was detected.').' Try a clearer photo showing the floor or opening.',
            'photo_quality_low' => 'This photo is too dark, bright, or blurred for a reliable preview. Upload a clear photo showing the usable floor or opening.',
            'preview_composite_unavailable' => 'The catalog image could not produce visible product pixels. Your browser preview is retained if available; ask the shop for a clear product photo or genuine cutout.',
            'gd_extension_missing' => 'Server image rendering is unavailable. Your normal browser 2D preview remains available; no paid enhancement was started.',
            default => 'Only a placement estimate is available; image rendering is not available on this server.',
        };
        $this->finish($generation, 'fallback', $failureCode, $message, $data, $confidence, $label, $this->metrics($started, $calls, null, null, null));
    }

    private function finish(RoomPreviewGeneration $generation, string $status, ?string $failureCode, ?string $message, ?array $data = null, ?float $confidence = null, ?string $label = null, ?array $metrics = null): void
    {
        $saved = DB::transaction(function () use ($generation, $status, $failureCode, $message, $data, $confidence, $label, $metrics): bool {
            $current = RoomPreviewGeneration::whereKey($generation->id)->lockForUpdate()->first();
            if (! $current || $current->status === 'cancelled' || $current->cache_key !== $generation->cache_key || $current->attempt_token !== $generation->attempt_token) {
                return false;
            }
            $current->update([
                'status' => $status, 'failure_code' => $failureCode, 'message' => $message,
                'placement_json' => $data, 'confidence' => $confidence, 'confidence_label' => $label,
                'metrics_json' => $metrics, 'completed_at' => now(),
            ]);

            return true;
        });
        if ($saved) {
            $this->telemetry->record('image', 'room_preview_v2', $status === 'completed' ? 'success' : $status, fallbackUsed: $status === 'fallback', failureCode: $failureCode);
        }
    }

    private function metrics(int $started, int $calls, ?array $quality, ?array $verification, ?array $guard): array
    {
        return [
            'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'provider_calls' => max(DB::table('ai_action_attempts')->where('action_key', 'like', 'preview-v2:'.$this->generationId.':%')->count(),
                $this->previousProviderCalls + max(0, $this->gemini->providerCallCount() - $this->providerCallStart)),
            'gd_available' => $this->raster->available(),
            'quality_usable' => $quality['usable'] ?? null,
            'verification_passed' => $verification['passed'] ?? null,
            'pixel_guard_passed' => $guard['passed'] ?? null,
        ];
    }

    private function expectedBounds(array $placement, FurnitureProduct $product, int $imageWidth, int $imageHeight): array
    {
        $width = max(1, min(90, (float) ($placement['max_width_percent'] ?? 35))) / 100 * $imageWidth;
        $ratio = (float) $product->height_cm > 0 && (float) $product->width_cm > 0 ? (float) $product->height_cm / (float) $product->width_cm : 1.0;
        $height = min($imageHeight * 0.92, $width * max(0.35, min(3.2, $ratio)));
        $x = max(0, min($imageWidth, $imageWidth * ((float) ($placement['x_percent'] ?? 50) / 100)));
        $bottom = max(0, min($imageHeight, $imageHeight * ((float) ($placement['y_percent'] ?? 86) / 100)));

        return [(int) ($x - $width / 2), (int) ($bottom - $height), (int) ($x + $width / 2), (int) $bottom];
    }
}
