<?php

namespace App\Jobs;

use App\Models\RoomImage;
use App\Models\RoomPreviewAnalysis;
use App\Services\AiAggregateBudgetExceeded;
use App\Services\AiRequestBudgetExceeded;
use App\Services\AiTelemetry;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeRoomImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public ?int $deadlineAt = null;

    public int $timeout = 240;

    public ?string $requestedAt = null;

    public function __construct(public int $roomImageId, public ?string $furnitureType = null, public bool $forceAnalysis = false)
    {
        $this->onQueue('ai');
        $this->deadlineAt = now()->addMinutes(10)->timestamp;
        $this->requestedAt = RoomImage::find($roomImageId)?->analysis_requested_at?->toISOString();
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function retryUntil(): \DateTimeInterface
    {
        // A fixed dispatch deadline bounds lock waits; never extend it on each attempt.
        return Carbon::createFromTimestamp($this->deadlineAt ?? now()->timestamp);
    }

    public function handle(GeminiService $gemini, RoomPhotoService $photos, PrivacyAudit $audit, AiTelemetry $telemetry): void
    {
        $lock = Cache::lock('ai-room-job:'.$this->roomImageId, 300);
        if (! $lock->get()) {
            return;
        }
        $previousActor = Context::getHidden('ai_user_id');
        $previousAction = Context::getHidden('ai_action_key');
        try {
            $this->perform($gemini, $photos, $audit, $telemetry);
        } finally {
            Context::forgetHidden('ai_photo_transfer');
            $previousAction === null ? Context::forgetHidden('ai_action_key') : Context::addHidden('ai_action_key', $previousAction);
            $previousActor === null ? Context::forgetHidden('ai_user_id') : Context::addHidden('ai_user_id', $previousActor);
            $lock->release();
        }
    }

    private function perform(GeminiService $gemini, RoomPhotoService $photos, PrivacyAudit $audit, AiTelemetry $telemetry): void
    {
        $room = RoomImage::find($this->roomImageId);

        if (! $room || ! in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true)
            || ($this->requestedAt !== null && $room->analysis_requested_at?->toISOString() !== $this->requestedAt)
            || ($this->requestedAt === null && $room->analysis_requested_at !== null)) {
            return;
        }
        Context::addHidden('ai_user_id', $room->user_id);
        Context::addHidden('ai_action_key', 'room-analysis:'.$room->id.':'.($this->requestedAt ?? $room->analysis_requested_at?->toISOString() ?? $room->created_at?->toISOString()));

        if (! $photos->exists($room->image_path)) {
            $telemetry->record('room', 'room_analysis', 'failure', failureCode: 'room_photo_missing');
            $this->updateState($room, [
                'analysis_status' => 'failed',
                'analysis_error' => 'The uploaded room image is no longer available.',
            ]);

            return;
        }

        if (! $this->updateState($room, [
            'analysis_status' => 'processing',
            'analysis_attempts' => $room->analysis_attempts + 1,
            'analysis_error' => null,
        ])) {
            return;
        }

        if (! \App\Services\AiProvider::hasPhotoConsent($room) || ! $gemini->isConfigured()) {
            $telemetry->record('room', 'room_analysis', 'fallback', fallbackUsed: true, failureCode: ! $room->gemini_consent_at ? 'consent_not_granted' : 'not_configured');
            $analysis = $gemini->roomFallback(null, null, $this->furnitureType);
            $message = ! $room->gemini_consent_at
                ? 'AI image processing was not authorized; local planning guidance is being used.'
                : 'AI is unavailable or consent is needed for the current provider; safe local placement guidance is being used.';
            $this->complete($room, $analysis, 'fallback', $message);

            return;
        }

        $version = (string) config('roompreview.pipeline_version', 'v2');
        $sharedGeometry = filled($room->image_sha256) && $this->furnitureType === 'all catalog furniture';
        $lock = $sharedGeometry ? Cache::lock('room-analysis:'.$room->image_sha256.':'.$version, 240) : null;
        if ($lock && ! $lock->get()) {
            $this->updateState($room, ['analysis_status' => 'queued', 'analysis_error' => 'This room photo is already being analyzed.']);
            $this->release(5);

            return;
        }

        try {
            $cached = $sharedGeometry ? RoomPreviewAnalysis::where('image_sha256', $room->image_sha256)
                ->where('pipeline_version', $version)->where('status', 'completed')->first() : null;
            if ($cached && $cached->analyzed_at && (! $this->forceAnalysis || $cached->analyzed_at->gte($room->analysis_requested_at ?? $room->created_at))
                && is_array($cached->analysis) && ($cached->analysis['gemini_used'] ?? false)
                && data_get($cached->analysis, 'vision_geometry.image_quality.usable') !== false) {
                $this->complete($room, $cached->analysis, 'completed');

                return;
            }
            \Illuminate\Support\Facades\Context::addHidden('ai_photo_transfer', ['room_id' => $room->id, 'purpose' => 'room_analysis', 'user_id' => $room->user_id]);
            $analysis = $gemini->analyzeRoom(
                $room->image_path,
                null,
                null,
                $this->furnitureType,
                true,
            );
            $usedAi = ($analysis['gemini_used'] ?? false) === true;
            $this->complete($room, $analysis, $usedAi ? 'completed' : 'fallback', $usedAi
                ? null
                : 'AI analysis did not return usable room geometry. Local guidance is being used; you can retry the analysis.');
        } catch (Throwable $exception) {
            $httpStatus = $telemetry->statusCode($exception);
            if ($exception instanceof AiAggregateBudgetExceeded || $exception instanceof AiRequestBudgetExceeded) {
                $this->complete($room, $gemini->roomFallback(null, null, $this->furnitureType), 'fallback', 'The application AI usage limit is reached. Local room guidance remains available.');

                return;
            }
            $transientFailure = $httpStatus === null
                || in_array($httpStatus, [408, 425, 429], true)
                || $httpStatus >= 500;

            if ($transientFailure && $this->attempts() < $this->tries) {
                if (! $this->updateState($room, [
                    'analysis_status' => 'retrying',
                    'analysis_error' => 'The AI service is temporarily unavailable. FurniCraft will retry automatically.',
                ])) {
                    return;
                }

                throw $exception;
            }

            $fallback = $gemini->roomFallback(null, null, $this->furnitureType);
            $providerReason = $this->providerReason($exception);
            $message = match ($httpStatus) {
                403 => 'Gemini access was denied'.($providerReason ? ': '.$providerReason : '.').' Ask the system administrator to check the API key and project permissions, then retry the analysis.',
                429 => 'Gemini has reached its request limit. Local room guidance is being used; wait a few minutes before retrying the analysis.',
                default => $transientFailure
                    ? 'Gemini is temporarily unavailable. Local room guidance is being used; you can retry the analysis.'
                    : 'Gemini could not process this analysis request. Local room guidance is being used; you can retry the analysis.',
            };
            $this->complete($room, $fallback, 'fallback', $message);
        } finally {
            $lock?->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        RoomImage::whereKey($this->roomImageId)->whereIn('analysis_status', ['queued', 'processing', 'retrying'])->update([
            'analysis_status' => 'failed',
            'analysis_error' => 'Room AI analysis could not be completed. You can retry without uploading the photo again.',
        ]);
    }

    private function updateState(RoomImage $snapshot, array $attributes): bool
    {
        return DB::transaction(function () use ($snapshot, $attributes) {
            $current = RoomImage::whereKey($snapshot->id)->lockForUpdate()->first();
            if (! $this->canPersist($snapshot, $current)) {
                return false;
            }

            $current->update($attributes);

            return true;
        });
    }

    private function canPersist(RoomImage $snapshot, ?RoomImage $current): bool
    {
        return $current
            && $current->analysis_requested_at == $snapshot->analysis_requested_at
            && $current->gemini_consent_at == $snapshot->gemini_consent_at;
    }

    private function providerReason(Throwable $exception): ?string
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $message = data_get($exception->response->json(), 'error.message');
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return Str::limit(preg_replace('/\s+/', ' ', trim($message)), 220, '…');
    }

    private function complete(RoomImage $room, array $analysis, string $status, ?string $message = null): void
    {
        if (data_get($analysis, 'vision_geometry.image_quality.usable') === false) {
            $analysis['gemini_used'] = false;
            $status = 'fallback';
            $message = 'This photo is not clear enough for AI placement. Upload a clearer photo; local guidance remains available.';
        }
        DB::transaction(function () use ($room, $analysis, $status, $message) {
            $current = RoomImage::whereKey($room->id)->lockForUpdate()->first();
            if (! $this->canPersist($room, $current)) {
                return;
            }

            // Preview jobs share this JSON field; retain their latest committed entries.
            foreach (['generated_previews', 'generated_preview_attempts'] as $key) {
                unset($analysis[$key]);
                if (array_key_exists($key, $current->analysis ?? [])) {
                    $analysis[$key] = $current->analysis[$key];
                }
            }
            $analysis['zone_analysis_attempted_at'] = now()->toIso8601String();
            $current->update([
                'room_width_m' => data_get($analysis, 'estimated_width_m'),
                'room_depth_m' => data_get($analysis, 'estimated_depth_m'),
                'analysis' => $analysis,
                'analysis_status' => $status,
                'analysis_error' => $message,
                'analyzed_at' => now(),
            ]);
            if ($status === 'completed' && $current->gemini_consent_at && filled($current->image_sha256)
                && $this->furnitureType === 'all catalog furniture' && ($analysis['gemini_used'] ?? false)) {
                $geometry = $analysis;
                unset($geometry['generated_previews'], $geometry['generated_preview_attempts']);
                RoomPreviewAnalysis::updateOrCreate(
                    ['image_sha256' => $current->image_sha256, 'pipeline_version' => (string) config('roompreview.pipeline_version', 'v2')],
                    ['status' => 'completed', 'analysis' => $geometry, 'failure_code' => null, 'analyzed_at' => now()],
                );
            }
        });
    }
}
